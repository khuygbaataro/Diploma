"""Tests for the list of students who need attention in chat. All students are invented."""

import asyncio
import logging

import pytest

from darimaa_bot import texts
from darimaa_bot.conversation import Bot, intent
from darimaa_bot.messenger import Incoming
from darimaa_bot.moodle import MoodleError

from .fakes import FakeMessenger, FakeMoodle, attention_student, course

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


def test_each_student_gets_one_line_with_the_reasons(setup, caplog):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101", students=25)]
    moodle.attention[12] = {"total": 25, "quizzes": 2, "students": [
        attention_student("B200910003", "Саруул Тэмүүлэн", ["never", "missing", "notattempted"],
                          missing=2, notattempted=1),
        attention_student("B200910002", "Болд Ганзориг", ["inactive", "lowquiz", "weaktopic"], dayssince=12,
                          quizaverage=45.0, weaktopics={"Эс": 55.0, "Удамшил": 30.0}),
    ]}

    with caplog.at_level(logging.DEBUG):
        run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:12", "k1")))

    assert messenger.last().text.split("\n") == [
        "«Биологи 101»: 25 оюутнаас 2 нь анхаарал хэрэгтэй:",
        "• B200910003 Саруул Тэмүүлэн: хичээлд огт ороогүй; илгээгээгүй даалгавар 2; өгөөгүй сорил 1",
        "• B200910002 Болд Ганзориг: 12 хоног ороогүй; сорилын дундаж 45%; сул сэдэв: Удамшил 30%, Эс 55%",
        "",
        "Эдгээр нь зөвхөн анхааруулга, дүн биш. Оюутантай ярилцахад туслах зорилготой.",
    ]
    assert moodle.attention_days == [7]
    # Only one message goes out, to the teacher. Nothing is sent to students.
    assert [recipient for recipient, _ in messenger.sent] == [TEACHER]
    assert "send_reminders" not in moodle.calls
    # Names and codes of students stay out of the log.
    for private in ("B2009", "Саруул", "Болд"):
        assert private not in caplog.text


def test_list_stops_at_fifteen_students(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101", students=40)]
    moodle.attention[12] = {"total": 40, "students": [
        attention_student(f"B2009100{n:02d}", f"Оюутан {n}", ["never"]) for n in range(1, 19)
    ]}

    run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:12", "k1")))

    lines = messenger.last().text.split("\n")
    assert lines[0] == "«Биологи 101»: 40 оюутнаас 18 нь анхаарал хэрэгтэй:"
    assert sum(1 for line in lines if line.startswith("• ")) == 15
    assert "…бас 3 оюутан байна. Бүгдийг Moodle-ийн «Students who need attention» хуудаснаас харна уу." in lines
    assert "Оюутан 16" not in messenger.last().text


def test_nobody_needs_attention_and_hidden_last_access(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101", students=3)]
    moodle.attention[12] = {"total": 3, "students": []}

    run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:12", "k1")))
    assert messenger.last().text == "«Биологи 101»: 3 оюутнаас анхаарал хэрэгтэй оюутан алга. Сайн байна!"

    # When the site hides the last access, the teacher is told why nobody is "away".
    moodle.attention[12] = {"total": 3, "lastaccessavailable": False, "students": [
        attention_student("B200910003", "Саруул Тэмүүлэн", ["missing"], missing=1),
    ]}
    run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:12", "k2")))
    lines = messenger.last().text.split("\n")
    assert lines[1] == "• B200910003 Саруул Тэмүүлэн: илгээгээгүй даалгавар 1"
    assert lines[-1] == texts.ATTENTION_HIDDEN


def test_teacher_with_one_course_gets_the_list_at_once(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101", students=3)]
    moodle.attention[12] = {"total": 3, "students": [attention_student("B200910003", "Саруул Тэмүүлэн", ["never"])]}

    run(bot.handle(text(TEACHER, "Асуудалтай оюутан байна уу?", "k1")))
    assert messenger.last().text.startswith("«Биологи 101»: 3 оюутнаас 1 нь анхаарал хэрэгтэй:")


def test_teacher_with_several_courses_picks_one(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101", students=25), course(13, "Хими", students=20)]

    run(bot.handle(tap(TEACHER, "ATTENTION", "k1")))
    reply = messenger.last()
    assert reply.text.startswith(texts.COURSE_PICK_HEADER)
    assert [payload for _, payload in reply.quick_replies] == ["ATTENTION_COURSE:12", "ATTENTION_COURSE:13", "CANCEL"]
    assert "get_attention" not in moodle.calls

    run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:13", "k2")))
    assert messenger.last().text.startswith("«Хими»: 0 оюутнаас анхаарал хэрэгтэй оюутан алга.")


def test_list_is_only_for_teachers_of_the_course(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101")]
    moodle.attention[12] = {"students": [attention_student("B200910003", "Саруул Тэмүүлэн", ["never"])]}

    # A student who sends the payload gets nothing, and Moodle is not even asked.
    run(bot.handle(tap(STUDENT, "ATTENTION_COURSE:12", "k1")))
    assert messenger.last().text == texts.NO_PERMISSION
    # A student who taps or types the report gets their own plan.
    run(bot.handle(tap(STUDENT, "ATTENTION", "k2")))
    assert messenger.last().text.startswith("Ойрын 7 хоногт хийх ажил алга")
    assert "get_attention" not in moodle.calls

    # A teacher asking about a course they do not teach is refused by Moodle.
    run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:99", "k3")))
    assert messenger.last().text == texts.NO_PERMISSION
    assert "Саруул" not in " ".join(messenger.texts())


def test_an_older_plugin_is_explained(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(12, "Биологи 101")]

    async def not_in_service(*args, **kwargs):
        raise MoodleError("accessexception", "Access control exception")

    async def unknown_function(*args, **kwargs):
        raise MoodleError("invalidrecord", "Can't find data record in database table external_functions.")

    moodle.get_attention = not_in_service
    run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:12", "k1")))
    assert messenger.last().text == texts.PLUGIN_TOO_OLD

    moodle.get_attention = unknown_function
    run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:12", "k2")))
    assert messenger.last().text == texts.PLUGIN_TOO_OLD

    # A missing record of another kind is an ordinary error.
    async def missing_course(*args, **kwargs):
        raise MoodleError("invalidrecord", "Can't find data record in database table course.")

    moodle.get_attention = missing_course
    run(bot.handle(tap(TEACHER, "ATTENTION_COURSE:12", "k3")))
    assert messenger.last().text == texts.ERROR


def test_attention_words():
    assert intent("анхаарах оюутан") == "ATTENTION"
    assert intent("Асуудалтай оюутнууд хэн бэ?") == "ATTENTION"
    assert intent("anhaarah oyutan") == "ATTENTION"
    assert intent("asuudaltai") == "ATTENTION"
    assert intent("students who need attention") == "ATTENTION"
    # The other reports keep their words.
    assert intent("идэвхгүй оюутан") == "INACTIVE"
    assert intent("сорилын дүн") == "QUIZ"
