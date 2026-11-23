"""Tests for a teacher's message to one student: the AI drafts it, the teacher confirms it. All students are invented."""

import asyncio
import json
import logging
from zoneinfo import ZoneInfo

import httpx

from darimaa_bot import texts
from darimaa_bot.ai import Answer, Assistant, Draft, OpenAIClient
from darimaa_bot.conversation import DRAFT_TTL, Bot
from darimaa_bot.messenger import Incoming

from .fakes import FakeMessenger, FakeMoodle, attention_student, course, roster_student
from .test_ai import API_KEY, FakeOpenAI, call, message

NOW = 1790000000.0
ZONE = ZoneInfo("Asia/Ulaanbaatar")
TEACHER = "tg-teacher"
STUDENT = "tg-student"


def school() -> FakeMoodle:
    moodle = FakeMoodle()
    moodle.links[TEACHER] = 9
    moodle.links[STUDENT] = 7
    moodle.courses[9] = [course(12, "D.CS101", students=12)]
    moodle.roster[9] = [
        roster_student(501, "sw26b022", "Khatnaa Khatanbold", 12, "D.CS101"),
        roster_student(502, "sw26b040", "Ariunbold Erkhbayar", 12, "D.CS101"),
    ]
    moodle.attention[12] = {"total": 12, "students": [
        attention_student("sw26b040", "Ariunbold Erkhbayar", ["never", "missing"], missing=10),
    ]}
    return moodle


def answer(fake: FakeOpenAI, question: str, moodle: FakeMoodle) -> Answer:
    async def go():
        async with httpx.AsyncClient(transport=httpx.MockTransport(fake)) as http:
            assistant = Assistant(OpenAIClient("https://api.openai.com/v1", API_KEY, "gpt-6.2-sol", http))
            return await assistant.answer(question, {"isteacher": True}, moodle, TEACHER, ZONE, NOW)
    return asyncio.run(go())


# The assistant drafts.


def test_assistant_finds_the_student_and_drafts_without_sending():
    moodle = school()
    fake = FakeOpenAI(
        {"output": [call("find_student", {"name": "khatnaa"}, "c1")]},
        {"output": [call("prepare_message", {"student": "S1", "text": "Сайн байна уу, S1! Хичээлдээ суугаарай."}, "c2")]},
        {"output": [message("S1-д явуулах мессежийг бэлдлээ. Уншаад «Тийм, явуул» дарна уу.")]},
    )
    result = answer(fake, "khatnaa ruu hicheeldee suugaarai geed moodle deer chat ywuul", moodle)

    assert result.text == "sw26b022 Khatnaa Khatanbold-д явуулах мессежийг бэлдлээ. Уншаад «Тийм, явуул» дарна уу."
    assert result.drafts == [Draft(courseid=12, userid=501, student="sw26b022 Khatnaa Khatanbold", course="D.CS101",
                                   text="Сайн байна уу, Khatnaa Khatanbold! Хичээлдээ суугаарай.")]
    # Nothing was sent: only the teacher's tap sends it.
    assert moodle.messages == []
    assert "send_message" not in moodle.calls
    # The model saw the label and the course, never the name, code or user id.
    found = json.loads(fake.requests[1]["input"][-1]["output"])
    assert found == [{"student": "S1", "course": "D.CS101", "courseid": 12}]
    for private in ("Khatanbold", "sw26b022", "501"):
        assert private not in json.dumps(fake.requests[1:], ensure_ascii=False)
    assert {tool["name"] for tool in fake.requests[0]["tools"]} >= {"find_student", "prepare_message"}


def test_a_student_from_a_report_is_looked_up_before_the_draft():
    moodle = school()
    fake = FakeOpenAI(
        {"output": [call("get_attention", {"courseid": 12}, "c1")]},
        {"output": [call("prepare_message", {"student": "S1", "text": "Даалгавраа илгээгээрэй."}, "c2")]},
        {"output": [message("Бэлэн.")]},
    )
    result = answer(fake, "хамгийн их хоцорсон оюутанд мессеж бэлд", moodle)
    assert [(draft.userid, draft.courseid, draft.student) for draft in result.drafts] == [
        (502, 12, "sw26b040 Ariunbold Erkhbayar")]
    assert moodle.calls.count("find_students") == 1


def test_drafts_are_checked():
    moodle = school()
    fake = FakeOpenAI(
        {"output": [
            call("prepare_message", {"student": "S7", "text": "Hi"}, "c1"),
            call("find_student", {"name": "khatnaa"}, "c2"),
        ]},
        {"output": [
            call("prepare_message", {"student": "S1", "text": "а" * 501}, "c3"),
            call("prepare_message", {"student": "S1", "text": "   "}, "c4"),
        ]},
        {"output": [message("Болсонгүй.")]},
    )
    result = answer(fake, "мессеж бэлд", moodle)
    assert result.drafts == []
    outputs = [json.loads(item["output"]) for request in fake.requests[1:]
               for item in request["input"] if item.get("type") == "function_call_output"]
    errors = [output["error"] for output in outputs if isinstance(output, dict) and "error" in output]
    assert errors[0] == "unknown student label: find the student first"
    assert errors[-2:] == ["the message must have 1 to 500 characters"] * 2


def test_students_cannot_draft_messages():
    moodle = school()
    fake = FakeOpenAI({"output": [message("Чадахгүй.")]})

    async def go():
        async with httpx.AsyncClient(transport=httpx.MockTransport(fake)) as http:
            assistant = Assistant(OpenAIClient("https://api.openai.com/v1", API_KEY, "gpt-6.2-sol", http))
            return await assistant.answer("найздаа мессеж явуул", {"isteacher": False}, moodle, STUDENT, ZONE, NOW)

    asyncio.run(go())
    assert [tool["name"] for tool in fake.requests[0]["tools"]] == ["get_my_deadlines"]


# The teacher confirms in the chat.


class DraftingAssistant:
    def __init__(self, drafts: list[Draft]) -> None:
        self.drafts = drafts

    def allow(self, sender: str) -> bool:
        return True

    async def answer(self, question, user, moodle, sender, zone, now) -> Answer:
        return Answer("Бэлдлээ.", list(self.drafts))


DRAFT = Draft(courseid=12, userid=501, student="sw26b022 Khatnaa Khatanbold", course="D.CS101",
              text="Сайн байна уу! Хичээлдээ суугаарай.")


def make_bot(drafts: list[Draft], clock=lambda: NOW):
    moodle, messenger = school(), FakeMessenger()
    bot = Bot(moodle, messenger, clock=clock, ai=DraftingAssistant(drafts))
    return bot, moodle, messenger


def ask(bot: Bot, sender: str, key: str) -> None:
    asyncio.run(bot.handle(Incoming(sender, "text", text="khatnaa ruu hicheeldee suugaarai geed chat ywuul", key=key)))


def tap(bot: Bot, sender: str, payload: str, key: str) -> None:
    asyncio.run(bot.handle(Incoming(sender, "postback", payload=payload, key=key)))


def test_the_teacher_reads_the_draft_and_sends_it_once(caplog):
    bot, moodle, messenger = make_bot([DRAFT])
    with caplog.at_level(logging.DEBUG):
        ask(bot, TEACHER, "k1")

    answer_reply, draft_reply = messenger.sent[-2][1], messenger.sent[-1][1]
    assert answer_reply.text.startswith("Бэлдлээ.")
    assert draft_reply.text == texts.MSG_CONFIRM.format(student="sw26b022 Khatnaa Khatanbold", course="D.CS101",
                                                        text="Сайн байна уу! Хичээлдээ суугаарай.")
    (yes, yes_payload), (no, no_payload) = draft_reply.buttons
    assert (yes, no, no_payload) == (texts.YES_SEND, texts.NO, "CANCEL")
    assert yes_payload.startswith("MSG:")
    # Shown, not sent.
    assert moodle.messages == []

    tap(bot, TEACHER, yes_payload, "k2")
    assert moodle.messages == [(9, 12, 501, "Сайн байна уу! Хичээлдээ суугаарай.")]
    assert messenger.last().text == texts.MSG_SENT.format(student="sw26b022 Khatnaa Khatanbold")

    # A second tap sends nothing.
    tap(bot, TEACHER, yes_payload, "k3")
    assert len(moodle.messages) == 1
    assert messenger.last().text == texts.MSG_GONE
    # The message and the student stay out of the log.
    for private in ("Khatnaa", "суугаарай"):
        assert private not in caplog.text


def test_cancel_old_drafts_and_other_accounts_send_nothing():
    now = [NOW]
    bot, moodle, messenger = make_bot([DRAFT], clock=lambda: now[0])
    ask(bot, TEACHER, "k1")
    payload = messenger.last().buttons[0][1]

    tap(bot, TEACHER, "CANCEL", "k2")
    assert messenger.last().text == texts.CANCELLED

    # Another account cannot use the teacher's button.
    moodle.links["tg-other-teacher"] = 9
    tap(bot, "tg-other-teacher", payload, "k3")
    assert messenger.last().text == texts.MSG_GONE
    # A student cannot either.
    tap(bot, STUDENT, payload, "k4")
    assert messenger.last().text == texts.NO_PERMISSION
    # After half an hour the draft is gone.
    now[0] += DRAFT_TTL + 1
    tap(bot, TEACHER, payload, "k5")
    assert messenger.last().text == texts.MSG_GONE
    assert moodle.messages == []


def test_moodle_refusals_are_explained():
    stranger = Draft(courseid=12, userid=999, student="sw26b099 Someone", course="D.CS101", text="Hi")
    bot, moodle, messenger = make_bot([stranger])
    ask(bot, TEACHER, "k1")
    tap(bot, TEACHER, messenger.last().buttons[0][1], "k2")
    assert messenger.last().text == texts.MSG_NOT_YOUR_STUDENT
    assert moodle.messages == []


def test_a_student_never_gets_draft_buttons():
    bot, _, messenger = make_bot([DRAFT])
    ask(bot, STUDENT, "k1")
    assert len(messenger.sent) == 1
    assert messenger.last().buttons == []
