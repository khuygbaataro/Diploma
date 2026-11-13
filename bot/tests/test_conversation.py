"""Tests for what the bot answers."""

import asyncio
from datetime import datetime
from zoneinfo import ZoneInfo

import pytest

from darimaa_bot.conversation import Bot, extract_code, intent
from darimaa_bot.messenger import Incoming

from .fakes import FakeMessenger, FakeMoodle

UB = ZoneInfo("Asia/Ulaanbaatar")
NOW = datetime(2026, 11, 6, 10, 0, tzinfo=UB).timestamp()  # Friday morning


def run(coro):
    return asyncio.run(coro)


@pytest.fixture
def setup():
    moodle = FakeMoodle()
    messenger = FakeMessenger()
    bot = Bot(moodle, messenger, clock=lambda: NOW)
    return bot, moodle, messenger


def text(sender: str, words: str, key: str) -> Incoming:
    return Incoming(sender, "text", text=words, key=key)


def tap(sender: str, payload: str, key: str) -> Incoming:
    return Incoming(sender, "postback", payload=payload, key=key)


def test_extract_code_and_intent():
    assert extract_code("ab23-cd45") == "AB23CD45"
    assert extract_code("Миний код: AB23 CD45 байна") == "AB23CD45"
    assert extract_code("ABCDEFGH") == ""  # needs a digit
    assert extract_code("12345678") == ""  # needs a letter
    assert extract_code("AB23CD450") == ""  # too long
    assert extract_code("AB1OCD34") == ""  # O is not used in codes
    assert intent("Энэ 7 хоногт юу хийх вэ?") == "PLAN"
    assert intent("hugatsaa heterseng haruul") == "PLAN"
    assert intent("Хугацаа хэтэрсэн ажил") == "OVERDUE"
    assert intent("илгээгээгүй хүмүүс") == "MISSING"
    assert intent("салгах") == "UNLINK"
    assert intent("sain uu") == "HELP"
    assert intent("?") == "HELP"
    assert intent("hiih") == ""  # "hi" only as a whole word
    assert intent("цаг агаар") == ""


def test_unlinked_user_gets_link_instructions_then_links_with_code(setup):
    bot, moodle, messenger = setup
    run(bot.handle(text("psid-a", "сайн уу", "k1")))
    assert FakeMoodle.LINKURL in messenger.last().text

    run(bot.handle(text("psid-a", "ab23-cd45", "k2")))
    assert moodle.links == {"psid-a": 7}
    assert "Боллоо, Anu!" in messenger.texts()[-2]
    assert [p for _, p in messenger.last().quick_replies] == ["PLAN", "OVERDUE", "HELP"]


def test_wrong_code_and_rate_limit(setup):
    bot, moodle, messenger = setup
    run(bot.handle(text("psid-a", "ZZ99ZZ99", "k1")))
    assert "Код буруу" in messenger.last().text
    for i in range(5):
        run(bot.handle(text("psid-a", "ZZ99ZZ9" + str(i + 2), f"r{i}")))
    assert "Хэт олон удаа" in messenger.last().text


def test_referral_ref_links_the_account(setup):
    bot, moodle, messenger = setup
    run(bot.handle(Incoming("psid-a", "referral", ref="AB23CD45", key="ref1")))
    assert moodle.links == {"psid-a": 7}
    # Opening the same m.me link again just greets.
    run(bot.handle(Incoming("psid-a", "postback", payload="GET_STARTED", ref="AB23CD45", key="ref2")))
    assert "Сайн байна уу, Anu!" in messenger.last().text


def test_plan_lists_overdue_and_upcoming_work(setup):
    bot, moodle, messenger = setup
    moodle.links["psid-a"] = 7
    due = datetime(2026, 11, 7, 23, 59, tzinfo=UB).timestamp()
    late = datetime(2026, 11, 4, 18, 0, tzinfo=UB).timestamp()
    moodle.deadlines[7] = [
        {"name": "Essay 1", "coursename": "History", "timedue": int(late), "url": "https://m/1", "overdue": True},
        {"name": "Lab report", "coursename": "Biology", "timedue": int(due), "url": "https://m/2", "overdue": False},
    ]
    run(bot.handle(text("psid-a", "7 хоногийн ажил", "k1")))
    reply = messenger.last().text
    assert reply.index("Хугацаа нь өнгөрсөн ажил:") < reply.index("Ойрын 7 хоногт хийх ажил:")
    assert "• Лхагва 11/04 18:00: Essay 1 (History)" in reply
    assert "• Маргааш 23:59: Lab report (Biology)" in reply
    assert "https://m/2" in reply

    run(bot.handle(tap("psid-a", "OVERDUE", "k2")))
    assert "Lab report" not in messenger.last().text
    assert "Essay 1" in messenger.last().text


def test_plan_when_nothing_is_due(setup):
    bot, moodle, messenger = setup
    moodle.links["psid-a"] = 7
    run(bot.handle(tap("psid-a", "PLAN", "k1")))
    assert messenger.last().text.startswith("Ойрын 7 хоногт хийх ажил алга")


def test_teacher_lists_missing_confirms_and_sends_once(setup):
    bot, moodle, messenger = setup
    moodle.links["psid-t"] = 9
    due = int(datetime(2026, 11, 5, 23, 59, tzinfo=UB).timestamp())
    moodle.missing[9] = [{"cmid": 42, "name": "Lab report", "coursename": "Biology", "duedate": due, "missing": 3}]

    run(bot.handle(text("psid-t", "илгээгээгүй", "k1")))
    reply = messenger.last()
    assert "1. Lab report (Biology): 3 оюутан, хугацаа Өчигдөр 23:59" in reply.text
    assert ("1. Lab report", "SEND_ASK:42") in reply.quick_replies

    run(bot.handle(tap("psid-t", "SEND_ASK:42", "k2")))
    assert messenger.last().buttons == [("Тийм, явуул", "SEND:42"), ("Болих", "CANCEL")]
    assert "3 оюутанд" in messenger.last().text

    run(bot.handle(tap("psid-t", "SEND:42", "k3")))
    assert messenger.last().text == "3 оюутанд сануулга явлаа."
    run(bot.handle(tap("psid-t", "SEND:42", "k4")))
    assert "саяхан явуулсан" in messenger.last().text
    assert moodle.sent == [(9, 42)]


def test_students_cannot_send_and_missing_shows_their_plan(setup):
    bot, moodle, messenger = setup
    moodle.links["psid-a"] = 7
    run(bot.handle(tap("psid-a", "SEND:42", "k1")))
    assert messenger.last().text == "Танд үүнийг хийх эрх алга."
    run(bot.handle(text("psid-a", "сануулга", "k2")))
    assert "Ойрын 7 хоногт" in messenger.last().text
    assert moodle.sent == []


def test_same_event_is_answered_once(setup):
    bot, moodle, messenger = setup
    run(bot.handle(text("psid-a", "сайн уу", "same")))
    run(bot.handle(text("psid-a", "сайн уу", "same")))
    assert len(messenger.sent) == 1


def test_unlink_needs_confirmation(setup):
    bot, moodle, messenger = setup
    moodle.links["psid-a"] = 7
    run(bot.handle(text("psid-a", "салгах", "k1")))
    assert messenger.last().buttons[0] == ("Тийм, салга", "UNLINK_YES")
    assert moodle.links == {"psid-a": 7}
    run(bot.handle(tap("psid-a", "UNLINK_YES", "k2")))
    assert moodle.links == {}


def test_moodle_errors_become_friendly_messages(setup):
    bot, moodle, messenger = setup
    moodle.links["psid-a"] = 7

    async def broken(*args, **kwargs):
        import httpx
        raise httpx.ConnectError("down")

    moodle.get_deadlines = broken
    run(bot.handle(tap("psid-a", "PLAN", "k1")))
    assert "алдаа гарлаа" in messenger.last().text
