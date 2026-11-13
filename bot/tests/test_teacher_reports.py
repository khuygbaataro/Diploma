"""Tests for the reports a teacher can ask for in chat. All students are invented."""

import asyncio
import logging

import pytest

from darimaa_bot import texts
from darimaa_bot.conversation import Bot
from darimaa_bot.messenger import Incoming

from .fakes import FakeMessenger, FakeMoodle, course, inactive_student

NOW = 1790000000.0
TEACHER = "psid-teacher"
STUDENT = "psid-student"


def run(coro):
    return asyncio.run(coro)


@pytest.fixture
def setup():
    moodle = FakeMoodle()
    moodle.links[TEACHER] = 9
    moodle.links[STUDENT] = 7
    messenger = FakeMessenger()
    bot = Bot(moodle, messenger, clock=lambda: NOW)
    return bot, moodle, messenger


def text(sender: str, words: str, key: str) -> Incoming:
    return Incoming(sender, "text", text=words, key=key)


def tap(sender: str, payload: str, key: str) -> Incoming:
    return Incoming(sender, "postback", payload=payload, key=key)


# Inactive students.


def test_inactive_list_shows_each_student_on_one_line(setup, caplog):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101", students=3)]
    moodle.inactive[12] = {"students": [
        inactive_student("B200910003", "Саруул Тэмүүлэн", dayssince=-1, minutes=0, missing=2),
        inactive_student("B200910002", "Болд Ганзориг", dayssince=12, minutes=95, missing=1),
        inactive_student("B200910001", "Ану Мөнх", dayssince=0, minutes=300, missing=0, inactive=False),
    ]}

    with caplog.at_level(logging.DEBUG):
        run(bot.handle(tap(TEACHER, "INACTIVE_COURSE:12", "k1")))

    assert messenger.last().text.split("\n") == [
        "«Биологи 101»: идэвхгүй 2 оюутан (сүүлийн 7 хоногт ороогүй):",
        "• B200910003 Саруул Тэмүүлэн: огт ороогүй, ≈0.0 цаг, илгээгээгүй даалгавар 2",
        "• B200910002 Болд Ганзориг: 12 хоногийн өмнө орсон, ≈1.6 цаг, илгээгээгүй даалгавар 1",
        "",
        "≈ тэмдэгтэй цаг нь Moodle-ийн бүртгэлээс тооцсон ойролцоо тоо.",
    ]
    assert "Ану" not in messenger.last().text
    assert moodle.inactive_days == [7]
    # Only one message goes out, to the teacher. Nothing is sent to students.
    assert [recipient for recipient, _ in messenger.sent] == [TEACHER]
    assert "send_reminders" not in moodle.calls
    # Names and codes of students stay out of the log.
    for private in ("B2009", "Саруул", "Болд", "Ану"):
        assert private not in caplog.text


def test_inactive_list_leaves_hours_out_when_there_is_no_log(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101")]
    moodle.inactive[12] = {"timeavailable": False, "students": [
        inactive_student("B200910003", "Саруул Тэмүүлэн", dayssince=-1, minutes=-1, missing=2),
    ]}

    run(bot.handle(tap(TEACHER, "INACTIVE_COURSE:12", "k1")))

    assert messenger.last().text.split("\n") == [
        "«Биологи 101»: идэвхгүй 1 оюутан (сүүлийн 7 хоногт ороогүй):",
        "• B200910003 Саруул Тэмүүлэн: огт ороогүй, илгээгээгүй даалгавар 2",
    ]
    assert "≈" not in messenger.last().text
    assert "цаг" not in messenger.last().text


def test_inactive_list_stops_at_fifteen_students(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101")]
    moodle.inactive[12] = {"students": [
        inactive_student(f"B2009100{number:02d}", f"Оюутан {number}", dayssince=8 + number) for number in range(1, 19)
    ]}

    run(bot.handle(tap(TEACHER, "INACTIVE_COURSE:12", "k1")))

    lines = messenger.last().text.split("\n")
    assert lines[0] == "«Биологи 101»: идэвхгүй 18 оюутан (сүүлийн 7 хоногт ороогүй):"
    assert len([line for line in lines if line.startswith("• ")]) == 15
    assert lines[15].startswith("• B200910015 Оюутан 15:")
    assert lines[16] == "…бас 3 оюутан байна."
    assert "Оюутан 16" not in messenger.last().text


def test_inactive_list_when_everybody_is_active_or_last_access_is_hidden(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101"), course(13, "Хими")]
    moodle.inactive[12] = {"students": [
        inactive_student("B200910001", "Ану Мөнх", dayssince=1, inactive=False),
    ]}
    moodle.inactive[13] = {"lastaccessavailable": False, "students": [
        inactive_student("B200910001", "Ану Мөнх", dayssince=-1, inactive=False),
    ]}

    run(bot.handle(tap(TEACHER, "INACTIVE_COURSE:12", "k1")))
    assert messenger.last().text == "«Биологи 101»: сүүлийн 7 хоногт бүх оюутан орж ирсэн байна."

    run(bot.handle(tap(TEACHER, "INACTIVE_COURSE:13", "k2")))
    assert messenger.last().text == texts.INACTIVE_HIDDEN


def test_inactive_list_is_only_for_teachers_of_the_course(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101")]
    moodle.inactive[12] = {"students": [inactive_student("B200910003", "Саруул Тэмүүлэн", dayssince=-1)]}

    # A student who sends the payload gets nothing, and Moodle is not even asked.
    run(bot.handle(tap(STUDENT, "INACTIVE_COURSE:12", "k1")))
    assert messenger.last().text == texts.NO_PERMISSION
    assert "get_inactive_students" not in moodle.calls

    # A teacher asking about a course they do not teach is refused by Moodle.
    run(bot.handle(tap(TEACHER, "INACTIVE_COURSE:99", "k2")))
    assert messenger.last().text == texts.NO_PERMISSION
    assert "Саруул" not in " ".join(messenger.texts())
