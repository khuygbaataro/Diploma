"""The AI assistant: answers free questions with the OpenAI Responses API and the plugin's read-only functions.

The model never sees a student's name, code or email. Each student in a tool result becomes a label
such as S3, and the bot puts the code and name back into the answer before it is sent. The model
only reads. It may prepare a message to a student, but the bot only shows it to the teacher with a
«Тийм, явуул» button: nothing reaches a student until the teacher taps it.
"""

from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Awaitable, Callable
from zoneinfo import ZoneInfo

import httpx

from .memory import RateLimiter
from .moodle import MoodleClient, MoodleError

log = logging.getLogger(__name__)

MAX_ROUNDS = 5
MAX_QUESTION = 1000
MAX_ROWS = 60
MAX_MESSAGE = 500
MAX_DRAFTS = 3
# A label may carry a Mongolian suffix after a hyphen, as in "S3-д".
LABEL_PATTERN = re.compile(r"(?<![\w-])S(\d{1,4})(?!\w)")

INSTRUCTIONS = """You are the "Teacher & Student Assistant" chatbot of a school's Moodle site.
Answer in plain, short, everyday Mongolian (Cyrillic script).

- Use the tools to get facts. Never invent numbers, names, dates or courses. If the tools do not
  give the answer, say so.
- Students appear as labels such as S3. Refer to a student only by that exact label. Never guess a
  real name. The app shows the real name in place of the label.
- You can only read data and prepare drafts. You cannot change anything in Moodle. To remind all
  students who have not submitted an assignment, tell the user to tap «Илгээгээгүй».
- A teacher can ask you to write to one student. Find the student first (find_student for a name
  the teacher typed), then call prepare_message. It is not sent: the teacher sees the draft with a
  confirm button. Write it in polite Mongolian, in the teacher's voice, at most 500 characters. You
  may use the student's label as their name (the app puts the real name in). Then tell the teacher
  to read the draft and tap «Тийм, явуул». If several students match, ask which one.
- The warning signs are hints for a conversation with the student, not a grade.
- If a question is not about the user's courses, deadlines or students, say briefly what you can
  help with.
- Plain text only: no Markdown, no tables, no headings. Use "• " for lists. At most 15 lines."""


class AIError(Exception):
    """The AI service could not be reached or refused the request."""


@dataclass
class Draft:
    """A message to a student that waits for the teacher's confirmation."""

    courseid: int
    userid: int
    student: str  # code and name, as the teacher knows the student
    course: str
    text: str


@dataclass
class Answer:
    """The assistant's reply, and any drafts the teacher still has to confirm."""

    text: str
    drafts: list[Draft] = field(default_factory=list)


class OpenAIClient:
    """Calls the Responses API with the API key in the Authorization header."""

    def __init__(self, api_url: str, api_key: str, model: str, http: httpx.AsyncClient) -> None:
        self.endpoint = f"{api_url.rstrip('/')}/responses"
        self.api_key = api_key
        self.model = model
        self.http = http
        # Encrypted reasoning lets a stateless conversation (store: false) continue after tool calls.
        self.include_reasoning = True

    async def respond(self, input_items: list[dict[str, Any]], tools: list[dict[str, Any]]) -> dict[str, Any]:
        body: dict[str, Any] = {
            "model": self.model,
            "instructions": INSTRUCTIONS,
            "input": input_items,
            "tools": tools,
            "store": False,
            "max_output_tokens": 4000,
        }
        if self.include_reasoning:
            body["include"] = ["reasoning.encrypted_content"]
        try:
            response = await self.http.post(
                self.endpoint,
                headers={"Authorization": f"Bearer {self.api_key}"},
                json=body,
                timeout=120,
            )
        except httpx.HTTPError as error:
            raise AIError(f"OpenAI could not be reached: {type(error).__name__}") from None
        try:
            data = response.json()
        except ValueError:
            data = {}
        if response.status_code >= 400:
            error = data.get("error") if isinstance(data, dict) else None
            error = error if isinstance(error, dict) else {}
            if response.status_code == 400 and error.get("param") == "include" and self.include_reasoning:
                # A model without reasoning items may not accept the field. Ask again without it.
                self.include_reasoning = False
                return await self.respond(input_items, tools)
            log.error("OpenAI refused a request: %s %s %s", response.status_code, error.get("type"), error.get("code"))
            raise AIError(f"OpenAI refused a request: {response.status_code} {error.get('code') or ''}".strip())
        return data


class Labels:
    """Gives each student a label for the model and puts the real code and name back afterwards."""

    def __init__(self) -> None:
        self._by_student: dict[tuple[str, str], str] = {}
        self._shown: dict[str, str] = {}
        self.rows: dict[str, dict[str, Any]] = {}

    def label(self, student: dict[str, Any], **known: Any) -> str:
        """The student's label. Known facts, such as the user id or course, are kept for a message."""
        key = (str(student.get("code", "")), str(student.get("fullname", "")))
        if key not in self._by_student:
            label = f"S{len(self._by_student) + 1}"
            self._by_student[key] = label
            self._shown[label] = " ".join(part for part in key if part) or label
            self.rows[label] = {"code": key[0], "fullname": key[1]}
        label = self._by_student[key]
        self.rows[label].update({name: value for name, value in (student | known).items()
                                 if name in ("userid", "courseid", "coursename") and value})
        return label

    def restore(self, text: str, with_code: bool = True) -> str:
        """Puts the students back: "code name" in answers, the name alone in a message to a student."""
        def shown(match: re.Match[str]) -> str:
            label = match.group(0)
            if label not in self._shown:
                return label
            return self._shown[label] if with_code else self.rows[label]["fullname"] or label
        return LABEL_PATTERN.sub(shown, text)


def _tool(name: str, description: str, properties: dict[str, Any] | None = None) -> dict[str, Any]:
    properties = properties or {}
    return {
        "type": "function",
        "name": name,
        "description": description,
        "parameters": {
            "type": "object",
            "properties": properties,
            "required": list(properties),
            "additionalProperties": False,
        },
        "strict": True,
    }


COURSE_ID = {"courseid": {"type": "integer", "description": "The course id from list_courses"}}

STUDENT_TOOLS = [
    _tool("get_my_deadlines", "The user's own open work: activities due in the next 14 days and overdue ones."),
]
TEACHER_TOOLS = STUDENT_TOOLS + [
    _tool("list_courses", "The courses the teacher teaches, with the number of students and the quizzes."),
    _tool(
        "get_attention",
        "Students of a course who need attention, with their warning signs: never visited, away for 7+ days, "
        "missing assignments, closed quizzes not attempted, quiz average or a quiz topic below 60%. "
        "days_since_visit -1 means never visited or hidden; quiz_average -1 means no finished quiz.",
        COURSE_ID,
    ),
    _tool(
        "get_inactive_students",
        "Every student of a course with days since the last visit, estimated minutes in the course "
        "and missing assignments. days_since_visit -1 means never visited or hidden; minutes -1 means unknown.",
        COURSE_ID | {"days": {"type": "integer", "description": "Days without a visit that count as inactive"}},
    ),
    _tool(
        "get_quiz_results",
        "The results of one quiz by topic: topic averages and each student's percentage by topic.",
        {"cmid": {"type": "integer", "description": "The quiz cmid from list_courses"}},
    ),
    _tool("get_missing_assignments", "The teacher's recent assignments and how many students have not submitted each."),
    _tool(
        "find_student",
        "Finds the teacher's students whose name or student code contains the text, with their course.",
        {"name": {"type": "string", "description": "Part of the name or code, as the teacher typed it"}},
    ),
    _tool(
        "prepare_message",
        "Prepares a Moodle chat message from the teacher to one student. It is NOT sent: the teacher "
        "confirms it with a button.",
        {
            "student": {"type": "string", "description": "The student's label, such as S3"},
            "text": {"type": "string", "description": "The message, plain text, at most 500 characters"},
        },
    ),
]


class Assistant:
    """Answers one free question of a linked user. Every Moodle call is made as that user."""

    def __init__(self, client: OpenAIClient, per_hour: int = 20) -> None:
        self.client = client
        self.limiter = RateLimiter(limit=per_hour, window=3600)

    def allow(self, sender: str) -> bool:
        return self.limiter.allow(sender)

    async def answer(self, question: str, user: dict[str, Any], moodle: MoodleClient, sender: str,
                     zone: ZoneInfo, now: float) -> Answer:
        labels = Labels()
        drafts: list[Draft] = []
        teacher = bool(user.get("isteacher"))
        tools = TEACHER_TOOLS if teacher else STUDENT_TOOLS
        today = datetime.fromtimestamp(now, zone)
        input_items: list[dict[str, Any]] = [
            {"role": "developer", "content": f"Today is {today:%A %Y-%m-%d %H:%M} ({zone.key}). "
                                             f"The user is a {'teacher' if teacher else 'student'}."},
            {"role": "user", "content": question[:MAX_QUESTION]},
        ]
        handlers = self._handlers(moodle, sender, labels, zone, teacher, drafts)

        for _ in range(MAX_ROUNDS):
            response = await self.client.respond(input_items, tools)
            output = response.get("output") or []
            calls = [item for item in output if isinstance(item, dict) and item.get("type") == "function_call"]
            if not calls:
                text = "\n".join(
                    part.get("text", "")
                    for item in output if isinstance(item, dict) and item.get("type") == "message"
                    for part in item.get("content") or [] if isinstance(part, dict) and part.get("type") == "output_text"
                ).strip()
                if not text:
                    raise AIError("OpenAI gave no text")
                usage = response.get("usage") or {}
                log.info("AI answered a question: %s input tokens, %s output tokens",
                         usage.get("input_tokens"), usage.get("output_tokens"))
                return Answer(labels.restore(text), drafts)

            # The model's own items go back unchanged, followed by one result for each call.
            input_items += output
            for call in calls:
                result = await self._run(handlers, str(call.get("name", "")), str(call.get("arguments") or "{}"))
                input_items.append({
                    "type": "function_call_output",
                    "call_id": call.get("call_id"),
                    "output": json.dumps(result, ensure_ascii=False),
                })
        raise AIError("Too many tool rounds")

    @staticmethod
    async def _run(handlers: dict[str, Callable[..., Awaitable[Any]]], name: str, arguments: str) -> Any:
        handler = handlers.get(name)
        if handler is None:
            return {"error": "unknown tool"}
        try:
            parsed = json.loads(arguments)
            if not isinstance(parsed, dict):
                raise ValueError
        except ValueError:
            return {"error": "the arguments are not a JSON object"}
        try:
            return await handler(**parsed)
        except TypeError:
            return {"error": "wrong arguments"}
        except MoodleError as error:
            # For example a course the user does not teach. The model is told, and tells the user.
            return {"error": error.errorcode or "refused"}

    def _handlers(self, moodle: MoodleClient, sender: str, labels: Labels, zone: ZoneInfo, teacher: bool,
                  drafts: list[Draft]) -> dict[str, Callable[..., Awaitable[Any]]]:
        def when(timestamp: Any) -> str:
            return datetime.fromtimestamp(int(timestamp), zone).strftime("%Y-%m-%d %H:%M") if timestamp else ""

        async def get_my_deadlines() -> Any:
            data = await moodle.get_deadlines(sender, days=14, overduedays=30)
            return [{"name": item.get("name"), "course": item.get("coursename"), "due": when(item.get("timedue")),
                     "overdue": bool(item.get("overdue"))} for item in data.get("items", [])[:MAX_ROWS]]

        async def list_courses() -> Any:
            data = await moodle.get_courses(sender)
            return [{"courseid": course.get("id"), "name": course.get("fullname"), "students": course.get("students"),
                     "quizzes": [{"cmid": quiz.get("cmid"), "name": quiz.get("name"), "finished": quiz.get("finished")}
                                 for quiz in course.get("quizzes", [])]}
                    for course in data.get("courses", [])]

        async def get_attention(courseid: int) -> Any:
            data = await moodle.get_attention(sender, int(courseid))
            return {
                "course": data.get("coursename"), "students_looked_at": data.get("total"),
                "last_visit_hidden": not data.get("lastaccessavailable", True),
                "students": [{
                    "student": labels.label(row, courseid=int(courseid)), "signs": row.get("signals", []),
                    "days_since_visit": row.get("dayssince"), "missing_assignments": row.get("missing"),
                    "quizzes_not_attempted": row.get("notattempted"), "quiz_average": row.get("quizaverage"),
                    "weak_topics": row.get("weaktopics", []),
                } for row in data.get("students", [])[:MAX_ROWS]],
            }

        async def get_inactive_students(courseid: int, days: int) -> Any:
            data = await moodle.get_inactive_students(sender, int(courseid), days=max(1, min(365, int(days))))
            return {
                "course": data.get("coursename"), "days": data.get("days"),
                "last_visit_hidden": not data.get("lastaccessavailable", True),
                "students": [{
                    "student": labels.label(row, courseid=int(courseid)), "days_since_visit": row.get("dayssince"),
                    "minutes_in_course": row.get("minutes"), "missing_assignments": row.get("missing"),
                    "inactive": row.get("inactive"),
                } for row in data.get("students", [])[:MAX_ROWS]],
            }

        async def get_quiz_results(cmid: int) -> Any:
            data = await moodle.get_quiz_topic_results(sender, int(cmid))
            return {
                "quiz": data.get("quizname"), "course": data.get("coursename"),
                "not_attempted": data.get("notattempted"),
                "topics": [{"name": topic.get("name"), "average": topic.get("average")} for topic in data.get("topics", [])],
                "students": [{
                    "student": labels.label(row), "percent": row.get("percent"), "ungraded": row.get("ungraded"),
                    "topics": [{"name": topic.get("name"), "percent": topic.get("percent")} for topic in row.get("topics", [])],
                } for row in data.get("students", [])[:MAX_ROWS]],
            }

        async def get_missing_assignments() -> Any:
            data = await moodle.get_missing(sender)
            return [{"name": row.get("name"), "course": row.get("coursename"), "due": when(row.get("duedate")),
                     "students_missing": row.get("missing")} for row in data.get("assignments", [])[:MAX_ROWS]]

        async def find_student(name: str) -> Any:
            data = await moodle.find_students(sender, str(name)[:100])
            return [{"student": labels.label(row), "course": row.get("coursename"), "courseid": row.get("courseid")}
                    for row in data.get("students", [])]

        async def prepare_message(student: str, text: str) -> Any:
            row = labels.rows.get(str(student))
            if row is None:
                return {"error": "unknown student label: find the student first"}
            text = labels.restore(str(text), with_code=False).strip()
            if not text or len(text) > MAX_MESSAGE:
                return {"error": f"the message must have 1 to {MAX_MESSAGE} characters"}
            if len(drafts) >= MAX_DRAFTS:
                return {"error": f"at most {MAX_DRAFTS} messages at a time"}
            if not row.get("userid"):
                # A student from a report: look them up to learn who exactly they are.
                found = (await moodle.find_students(sender, row["fullname"] or row["code"])).get("students", [])
                same = [hit for hit in found if hit.get("code") == row["code"] and hit.get("fullname") == row["fullname"]]
                if row.get("courseid"):
                    same = [hit for hit in same if hit.get("courseid") == row["courseid"]] or same
                if not same:
                    return {"error": "the student was not found"}
                labels.label(same[0])
            drafts.append(Draft(
                courseid=int(row["courseid"]), userid=int(row["userid"]),
                student=labels.restore(str(student)), course=str(row.get("coursename", "")), text=text,
            ))
            return {"status": "draft ready: the teacher must read it and tap the confirm button"}

        handlers: dict[str, Callable[..., Awaitable[Any]]] = {"get_my_deadlines": get_my_deadlines}
        if teacher:
            handlers |= {
                "list_courses": list_courses,
                "get_attention": get_attention,
                "get_inactive_students": get_inactive_students,
                "get_quiz_results": get_quiz_results,
                "get_missing_assignments": get_missing_assignments,
                "find_student": find_student,
                "prepare_message": prepare_message,
            }
        return handlers
