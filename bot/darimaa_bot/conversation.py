"""Decides what to answer. Rules for now: an AI model can later take over `intent`."""

from __future__ import annotations

import logging
import re
import time
from datetime import datetime, timedelta
from typing import Any, Callable
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

import httpx

from . import texts
from .memory import RateLimiter, TTLSet
from .messenger import Incoming, MessengerClient, Reply, account_ref
from .moodle import MoodleClient, MoodleError

log = logging.getLogger(__name__)

CODE_ALPHABET = set("ABCDEFGHJKLMNPQRSTUVWXYZ23456789")
CODE_PATTERN = re.compile(r"(?<![A-Za-z0-9])([A-Za-z0-9]{4})[-\s]?([A-Za-z0-9]{4})(?![A-Za-z0-9])")
MAX_ITEMS = 8
MAX_ASSIGNMENTS = 10
MAX_STUDENTS = 15
MAX_CHOICES = 10
INACTIVE_DAYS = 7

# The teacher reports start with a course. Each one continues with this payload plus the course id.
COURSE_STEPS = {"INACTIVE": "INACTIVE_COURSE", "QUIZ": "QUIZ_COURSE"}

# Checked in this order. Short words must match as whole words, longer ones anywhere.
KEYWORDS: list[tuple[str, list[str]]] = [
    ("UNLINK", ["салгах", "салга", "salgah", "unlink"]),
    ("OVERDUE", ["хоцорсон", "хоцорч", "хэтэрсэн", "hotsorson", "hocorson", "overdue"]),
    ("MISSING", ["илгээгээгүй", "сануулга", "ilgeegeegui", "sanuulga", "missing"]),
    # The two teacher reports come before PLAN, so that «Сорилын хугацаа» asks about a quiz.
    ("INACTIVE", ["идэвхгүй", "идэвхигүй", "idevhgui", "idewhgui", "inactive"]),
    ("QUIZ", ["сорил", "сул сэдэв", "soril", "sul sedev", "quiz"]),
    ("PLAN", [
        "7 хоног", "долоо хоног", "төлөвлөгөө", "хугацаа", "даалгавар", "юу хийх", "хийх ажил",
        "7 honog", "doloo honog", "tolovlogoo", "hugatsaa", "daalgavar", "yu hiih", "deadline", "plan",
    ]),
    ("HELP", [
        "тусламж", "туслаач", "цэс", "сайн уу", "сайн байна", "sain uu", "sain baina", "sain bn",
        "help", "menu", "hi", "hello",
    ]),
]

ERROR_TEXTS = {
    "nopermissions": texts.NO_PERMISSION,
    "unsupportedanonymous": texts.ANONYMOUS,
}


def extract_code(text: str) -> str:
    """Finds a link code (8 letters and digits, maybe with a dash) in what the user typed."""
    for match in CODE_PATTERN.finditer(text or ""):
        code = (match.group(1) + match.group(2)).upper()
        if set(code) <= CODE_ALPHABET and any(c.isdigit() for c in code) and any(c.isalpha() for c in code):
            return code
    return ""


def intent(text: str) -> str:
    """Guesses what the user wants from free text."""
    words = (text or "").strip().lower()
    if not words:
        return ""
    if words in {"?", "??"}:
        return "HELP"
    for action, keywords in KEYWORDS:
        for keyword in keywords:
            if len(keyword) >= 4 or " " in keyword:
                if keyword in words:
                    return action
            elif re.search(rf"(?<!\w){re.escape(keyword)}(?!\w)", words):
                return action
    return ""


class Bot:
    """Answers one Messenger event at a time."""

    def __init__(
        self,
        moodle: MoodleClient,
        messenger: MessengerClient,
        default_timezone: str = "Asia/Ulaanbaatar",
        clock: Callable[[], float] = time.time,
        weak_topic_below: float = 60.0,
        exam_points: float = 30.0,
    ) -> None:
        self.moodle = moodle
        self.messenger = messenger
        self.default_timezone = default_timezone
        self.clock = clock
        self.weak_topic_below = weak_topic_below
        self.exam_points = exam_points
        self.handled = TTLSet(ttl=3600)
        self.code_attempts = RateLimiter(limit=5, window=3600)
        self.recent_sends = TTLSet(ttl=600)

    async def handle(self, event: Incoming) -> None:
        """Answers an event, unless it was already answered (Meta can deliver an event twice)."""
        if self.handled.seen(event.key):
            return
        try:
            replies = await self.respond(event)
        except MoodleError as error:
            log.warning("Moodle refused a request for account %s: %s", account_ref(event.sender_id), error)
            replies = [Reply(ERROR_TEXTS.get(error.errorcode, texts.ERROR))]
        except httpx.HTTPError as error:
            log.error("Could not reach Moodle: %s", error)
            replies = [Reply(texts.ERROR)]
        except Exception:
            # Anything else is a bug or an answer we did not expect. The user still gets a reply.
            log.exception("Unexpected error while answering account %s", account_ref(event.sender_id))
            replies = [Reply(texts.ERROR)]

        for reply in replies:
            try:
                await self.messenger.send(event.sender_id, reply)
            except httpx.HTTPError as error:
                log.error("Could not send a reply to Messenger: %s", error)
                return

    async def respond(self, event: Incoming) -> list[Reply]:
        sender = event.sender_id

        code = event.ref.strip() or (extract_code(event.text) if event.kind == "text" else "")
        if code:
            return await self.link(sender, code)

        user = await self.moodle.get_user(sender)
        if not user.get("linked"):
            return [Reply(texts.not_linked(user.get("linkurl", "")))]

        if event.kind == "other":
            return [Reply(texts.NON_TEXT, self.menu(user))]

        action = event.payload or intent(event.text)
        name, _, argument = action.partition(":")

        if name == "GET_STARTED":
            return [Reply(f"{texts.GREETING.format(firstname=user.get('firstname', ''))}\n\n{self.help_text(user)}",
                          self.menu(user))]
        if name == "HELP":
            return [Reply(self.help_text(user), self.menu(user))]
        if name == "PLAN":
            return await self.plan(sender, user)
        if name == "OVERDUE":
            return await self.plan(sender, user, overdue_only=True)
        if name == "MISSING":
            if not user.get("isteacher"):
                return await self.plan(sender, user)
            return await self.missing(sender, user)
        if name == "SEND_ASK" and argument.isdigit():
            return await self.confirm_send(sender, user, int(argument))
        if name == "SEND" and argument.isdigit():
            return await self.send(sender, user, int(argument))
        if name in COURSE_STEPS:
            # The reports are for teachers. Anybody else gets their own plan, as for "PLAN".
            if not user.get("isteacher"):
                return await self.plan(sender, user)
            return await self.choose_course(sender, user, name)
        if name == "INACTIVE_COURSE" and argument.isdigit():
            return await self.inactive(sender, user, int(argument))
        if name == "QUIZ_COURSE" and argument.isdigit():
            return await self.choose_quiz(sender, user, int(argument))
        if name == "QUIZ_SHOW" and argument.isdigit():
            return await self.quiz_results(sender, user, int(argument))
        if name == "UNLINK":
            return [Reply(texts.UNLINK_CONFIRM, buttons=[(texts.YES_UNLINK, "UNLINK_YES"), (texts.NO, "CANCEL")])]
        if name == "UNLINK_YES":
            await self.moodle.unlink_account(sender)
            return [Reply(texts.UNLINK_DONE)]
        if name == "CANCEL":
            return [Reply(texts.CANCELLED, self.menu(user))]
        return [Reply(texts.UNKNOWN, self.menu(user))]

    async def link(self, sender: str, code: str) -> list[Reply]:
        if not self.code_attempts.allow(sender):
            return [Reply(texts.LINK_TOO_MANY)]

        result = await self.moodle.link_account(sender, code)
        user = await self.moodle.get_user(sender)
        if result.get("linked"):
            return [
                Reply(texts.LINK_OK.format(firstname=result.get("firstname", ""))),
                Reply(self.help_text(user), self.menu(user)),
            ]
        if user.get("linked"):
            # An old code, for example from opening the m.me link again: the account is already linked.
            return [Reply(f"{texts.GREETING.format(firstname=user.get('firstname', ''))}\n\n{self.help_text(user)}",
                          self.menu(user))]
        return [Reply(texts.LINK_FAILED.format(linkurl=user.get("linkurl", "")))]

    async def plan(self, sender: str, user: dict[str, Any], overdue_only: bool = False) -> list[Reply]:
        if overdue_only:
            data = await self.moodle.get_deadlines(sender, days=1, overduedays=30)
        else:
            data = await self.moodle.get_deadlines(sender, days=7, overduedays=14)
        if not data.get("linked"):
            return [Reply(texts.not_linked(user.get("linkurl", "")))]

        zone = self.zone(user.get("timezone"))
        items = data.get("items", [])
        overdue = [item for item in items if item.get("overdue")]
        upcoming = [item for item in items if not item.get("overdue")]

        lines: list[str] = []
        if overdue_only:
            if not overdue:
                return [Reply(texts.OVERDUE_EMPTY, self.menu(user))]
            lines.append(texts.OVERDUE_HEADER)
            lines += self.item_lines(overdue, zone)
        else:
            if not items:
                return [Reply(texts.PLAN_EMPTY, self.menu(user))]
            if overdue:
                lines.append(texts.OVERDUE_HEADER)
                lines += self.item_lines(overdue, zone)
                lines.append("")
            if upcoming:
                lines.append(texts.PLAN_HEADER)
                lines += self.item_lines(upcoming, zone)
            else:
                lines.append(texts.PLAN_EMPTY)
        return [Reply("\n".join(lines), self.menu(user))]

    async def missing(self, sender: str, user: dict[str, Any]) -> list[Reply]:
        rows = (await self.moodle.get_missing(sender)).get("assignments", [])
        if not rows:
            return [Reply(texts.MISSING_EMPTY, self.menu(user))]

        zone = self.zone(user.get("timezone"))
        lines = [texts.MISSING_HEADER]
        choices: list[tuple[str, str]] = []
        for number, row in enumerate(rows[:MAX_ASSIGNMENTS], start=1):
            lines.append(texts.MISSING_LINE.format(
                n=number,
                name=row["name"],
                course=row["coursename"],
                missing=row["missing"],
                when=self.when(row["duedate"], zone),
            ))
            choices.append((f"{number}. {row['name']}", f"SEND_ASK:{row['cmid']}"))
        lines += ["", texts.MISSING_PICK]
        return [Reply("\n".join(lines), choices + [(texts.NO, "CANCEL")])]

    async def confirm_send(self, sender: str, user: dict[str, Any], cmid: int) -> list[Reply]:
        if not user.get("isteacher"):
            return [Reply(texts.NO_PERMISSION, self.menu(user))]
        rows = (await self.moodle.get_missing(sender)).get("assignments", [])
        row = next((r for r in rows if int(r["cmid"]) == cmid), None)
        if not row:
            return [Reply(texts.SEND_GONE, self.menu(user))]
        text = texts.SEND_CONFIRM.format(name=row["name"], missing=row["missing"])
        return [Reply(text, buttons=[(texts.YES_SEND, f"SEND:{cmid}"), (texts.NO, "CANCEL")])]

    async def send(self, sender: str, user: dict[str, Any], cmid: int) -> list[Reply]:
        if not user.get("isteacher"):
            return [Reply(texts.NO_PERMISSION, self.menu(user))]
        key = f"{sender}:{cmid}"
        if key in self.recent_sends:
            return [Reply(texts.SEND_RECENT, self.menu(user))]
        # Remember before sending, so a double tap cannot send twice.
        self.recent_sends.add(key)
        try:
            result = await self.moodle.send_reminders(sender, cmid)
        except Exception:
            self.recent_sends.discard(key)
            raise
        if not result.get("sent"):
            return [Reply(texts.SEND_NONE, self.menu(user))]
        return [Reply(texts.SEND_DONE.format(sent=result["sent"]), self.menu(user))]

    async def choose_course(self, sender: str, user: dict[str, Any], report: str) -> list[Reply]:
        """Asks a teacher which course a report is about. With one course there is nothing to ask."""
        courses = (await self.moodle.get_courses(sender)).get("courses", [])
        if not courses:
            return [Reply(texts.COURSES_NONE, self.menu(user))]
        if len(courses) == 1:
            courseid = int(courses[0]["id"])
            if report == "INACTIVE":
                return await self.inactive(sender, user, courseid)
            return await self.choose_quiz(sender, user, courseid)

        lines = [texts.COURSE_PICK_HEADER]
        choices: list[tuple[str, str]] = []
        for number, course in enumerate(courses[:MAX_CHOICES], start=1):
            lines.append(texts.COURSE_PICK_LINE.format(n=number, name=course["fullname"], students=course["students"]))
            choices.append((f"{number}. {course['fullname']}", f"{COURSE_STEPS[report]}:{course['id']}"))
        if len(courses) > MAX_CHOICES:
            lines.append(texts.COURSE_MORE.format(count=len(courses) - MAX_CHOICES))
        lines += ["", texts.COURSE_PICK]
        return [Reply("\n".join(lines), choices + [(texts.NO, "CANCEL")])]

    async def inactive(self, sender: str, user: dict[str, Any], courseid: int) -> list[Reply]:
        """Shows a teacher the inactive students of one course. Nothing is sent to the students."""
        if not user.get("isteacher"):
            return [Reply(texts.NO_PERMISSION, self.menu(user))]
        data = await self.moodle.get_inactive_students(sender, courseid, days=INACTIVE_DAYS)
        if not data.get("linked"):
            return [Reply(texts.not_linked(user.get("linkurl", "")))]
        if not data.get("lastaccessavailable", True):
            return [Reply(texts.INACTIVE_HIDDEN, self.menu(user))]

        course = data.get("coursename", "")
        days = data.get("days", INACTIVE_DAYS)
        students = [student for student in data.get("students", []) if student.get("inactive")]
        if not students:
            return [Reply(texts.INACTIVE_EMPTY.format(course=course, days=days), self.menu(user))]

        with_hours = bool(data.get("timeavailable"))
        lines = [texts.INACTIVE_HEADER.format(course=course, count=len(students), days=days)]
        for student in students[:MAX_STUDENTS]:
            dayssince = int(student.get("dayssince", -1))
            template = texts.INACTIVE_LINE if with_hours else texts.INACTIVE_LINE_NO_HOURS
            lines.append(template.format(
                student=self.student_label(student),
                last=texts.INACTIVE_NEVER if dayssince < 0 else texts.INACTIVE_DAYS_AGO.format(days=dayssince),
                hours=f"{max(0, int(student.get('minutes', 0))) / 60:.1f}",
                missing=student.get("missing", 0),
            ))
        if len(students) > MAX_STUDENTS:
            lines.append(texts.INACTIVE_MORE.format(count=len(students) - MAX_STUDENTS))
        if with_hours:
            lines += ["", texts.INACTIVE_HOURS_NOTE]
        return [Reply("\n".join(lines), self.menu(user))]

    async def choose_quiz(self, sender: str, user: dict[str, Any], courseid: int) -> list[Reply]:
        """Lets a teacher pick a quiz of a course. Only quizzes that students have finished are offered."""
        if not user.get("isteacher"):
            return [Reply(texts.NO_PERMISSION, self.menu(user))]
        courses = (await self.moodle.get_courses(sender)).get("courses", [])
        course = next((row for row in courses if int(row["id"]) == courseid), None)
        if not course:
            return [Reply(texts.COURSE_GONE, self.menu(user))]

        quizzes = [quiz for quiz in course.get("quizzes", []) if int(quiz.get("finished", 0)) > 0]
        if not quizzes:
            return [Reply(texts.QUIZ_NONE.format(course=course["fullname"]), self.menu(user))]
        if len(quizzes) == 1:
            return await self.quiz_results(sender, user, int(quizzes[0]["cmid"]))

        lines = [texts.QUIZ_PICK_HEADER.format(course=course["fullname"])]
        choices: list[tuple[str, str]] = []
        for number, quiz in enumerate(quizzes[:MAX_CHOICES], start=1):
            lines.append(texts.QUIZ_PICK_LINE.format(n=number, name=quiz["name"], finished=quiz["finished"]))
            choices.append((f"{number}. {quiz['name']}", f"QUIZ_SHOW:{quiz['cmid']}"))
        lines += ["", texts.QUIZ_PICK]
        return [Reply("\n".join(lines), choices + [(texts.NO, "CANCEL")])]

    async def quiz_results(self, sender: str, user: dict[str, Any], cmid: int) -> list[Reply]:
        """Shows a teacher the results of one quiz by topic. Nothing is sent to the students."""
        if not user.get("isteacher"):
            return [Reply(texts.NO_PERMISSION, self.menu(user))]
        data = await self.moodle.get_quiz_topic_results(sender, cmid)
        if not data.get("linked"):
            return [Reply(texts.not_linked(user.get("linkurl", "")))]

        names = {"quiz": data.get("quizname", ""), "course": data.get("coursename", "")}
        students = data.get("students", [])
        if not students:
            return [Reply(texts.QUIZ_NO_ATTEMPTS.format(**names), self.menu(user))]

        lines = [texts.QUIZ_HEADER.format(attempted=len(students), notattempted=data.get("notattempted", 0), **names)]

        topics = sorted(data.get("topics", []), key=lambda topic: (float(topic["average"]), topic["name"]))
        if topics:
            lines += ["", texts.QUIZ_TOPICS_HEADER]
            lines += [texts.QUIZ_TOPIC_LINE.format(name=topic["name"], average=self.number(topic["average"]))
                      for topic in topics]

        # A topic counts as weak only when it has marked questions: an essay that waits for a mark is not weak.
        weak: list[tuple[dict[str, Any], list[dict[str, Any]]]] = []
        for student in students:
            low = [topic for topic in student.get("topics", [])
                   if float(topic.get("maxmark", 0)) > 0 and float(topic["percent"]) < self.weak_topic_below]
            if low:
                weak.append((student, sorted(low, key=lambda topic: (float(topic["percent"]), topic["name"]))))
        weak.sort(key=lambda pair: (float(pair[0]["percent"]), str(pair[0].get("fullname", ""))))

        threshold = self.number(self.weak_topic_below)
        lines.append("")
        if not weak:
            lines.append(texts.QUIZ_NO_WEAK.format(threshold=threshold))
        else:
            lines.append(texts.QUIZ_WEAK_HEADER.format(threshold=threshold))
            for student, low in weak[:MAX_STUDENTS]:
                lines.append(texts.QUIZ_WEAK_LINE.format(
                    student=self.student_label(student),
                    percent=self.number(student["percent"]),
                    estimate=f"{float(student['percent']) * self.exam_points / 100:.1f}",
                    points=self.number(self.exam_points),
                    topics=", ".join(texts.QUIZ_WEAK_TOPIC.format(name=topic["name"], percent=self.number(topic["percent"]))
                                     for topic in low),
                ))
            if len(weak) > MAX_STUDENTS:
                lines.append(texts.QUIZ_MORE.format(count=len(weak) - MAX_STUDENTS))
            lines += ["", texts.QUIZ_ESTIMATE_NOTE]
        if any(int(student.get("ungraded", 0)) > 0 for student in students):
            lines.append(texts.QUIZ_UNGRADED_NOTE)
        return [Reply("\n".join(lines), self.menu(user))]

    @staticmethod
    def number(value: Any) -> str:
        """A number without a needless ".0": 70.0 becomes 70, 71.5 stays 71.5."""
        return f"{float(value):g}"

    @staticmethod
    def student_label(student: dict[str, Any]) -> str:
        """The student code and name, as teachers know the student."""
        return " ".join(part for part in (str(student.get("code", "")), str(student.get("fullname", ""))) if part)

    def menu(self, user: dict[str, Any]) -> list[tuple[str, str]]:
        choices = [(texts.MENU_PLAN, "PLAN"), (texts.MENU_OVERDUE, "OVERDUE")]
        if user.get("isteacher"):
            choices.append((texts.MENU_MISSING, "MISSING"))
            choices.append((texts.MENU_INACTIVE, "INACTIVE"))
            choices.append((texts.MENU_QUIZ, "QUIZ"))
        choices.append((texts.MENU_HELP, "HELP"))
        return choices

    def help_text(self, user: dict[str, Any]) -> str:
        return texts.HELP_STUDENT + (texts.HELP_TEACHER if user.get("isteacher") else "")

    def zone(self, name: str | None) -> ZoneInfo:
        for candidate in (name, self.default_timezone, "UTC"):
            if candidate:
                try:
                    return ZoneInfo(candidate)
                except (ZoneInfoNotFoundError, ValueError):
                    continue
        return ZoneInfo("UTC")

    def when(self, timestamp: int, zone: ZoneInfo) -> str:
        moment = datetime.fromtimestamp(int(timestamp), zone)
        today = datetime.fromtimestamp(self.clock(), zone).date()
        if moment.date() == today:
            day = texts.TODAY
        elif moment.date() == today + timedelta(days=1):
            day = texts.TOMORROW
        elif moment.date() == today - timedelta(days=1):
            day = texts.YESTERDAY
        else:
            day = f"{texts.WEEKDAYS[moment.weekday()]} {moment:%m/%d}"
        return f"{day} {moment:%H:%M}"

    def item_lines(self, items: list[dict[str, Any]], zone: ZoneInfo) -> list[str]:
        lines = []
        for item in items[:MAX_ITEMS]:
            course = f" ({item['coursename']})" if item.get("coursename") else ""
            lines.append(f"• {self.when(item['timedue'], zone)}: {item['name']}{course}")
            if item.get("url"):
                lines.append(f"  {item['url']}")
        if len(items) > MAX_ITEMS:
            lines.append(texts.MORE_ITEMS.format(count=len(items) - MAX_ITEMS))
        return lines
