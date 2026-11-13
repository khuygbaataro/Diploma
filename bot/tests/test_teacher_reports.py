"""Tests for the reports a teacher can ask for in chat. All students are invented."""

import asyncio
import logging

import pytest

from darimaa_bot import texts
from darimaa_bot.conversation import Bot, intent
from darimaa_bot.messenger import Incoming

from .fakes import FakeMessenger, FakeMoodle, course, inactive_student, quiz, quiz_student

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


# Quiz results by topic.


def biology_with_quizzes(moodle: FakeMoodle) -> None:
    """One course with three quizzes. Nobody has finished the second quiz yet."""
    moodle.courses[9] = [course(12, "Биологи 101", students=5, quizzes=[
        quiz(345, "Сорил 1", finished=3),
        quiz(346, "Сорил 2", finished=0),
        quiz(347, "Эцсийн сорил", finished=2),
    ])]
    moodle.quiz_results[345] = {
        "notattempted": 2,
        "topics": [
            {"name": "Эс", "maxmark": 10, "average": 71.5},
            {"name": "Удамшил", "maxmark": 10, "average": 48.0},
            {"name": "Экологи", "maxmark": 10, "average": 90.0},
        ],
        "students": [
            quiz_student("B200910001", "Ану Мөнх", 60.0, {"Эс": 80, "Удамшил": 40, "Экологи": 60}),
            quiz_student("B200910002", "Болд Ганзориг", 85.0, {"Эс": 90, "Удамшил": 75, "Экологи": 90}),
            quiz_student("B200910003", "Саруул Тэмүүлэн", 41.7, {"Эс": 45, "Удамшил": 30, "Экологи": 50}),
        ],
    }


def test_quiz_results_show_topics_weakest_first_and_students_with_weak_topics(setup, caplog):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)

    with caplog.at_level(logging.DEBUG):
        run(bot.handle(tap(TEACHER, "QUIZ_SHOW:345", "k1")))

    assert messenger.last().text.split("\n") == [
        "«Сорил 1» (Биологи 101): 3 оюутан өгсөн, 2 оюутан өгөөгүй.",
        "",
        "Сэдвүүд, хамгийн сулаас нь:",
        "• Удамшил: дундаж 48%",
        "• Эс: дундаж 71.5%",
        "• Экологи: дундаж 90%",
        "",
        "60%-иас доош сэдэвтэй оюутнууд:",
        "• B200910003 Саруул Тэмүүлэн: сорил 41.7%, шалгалтын ойролцоо оноо ≈12.5/30. "
        "Сул сэдэв: Удамшил 30%, Эс 45%, Экологи 50%",
        # 60 percent of 30 points. A topic at exactly 60 percent is not weak.
        "• B200910001 Ану Мөнх: сорил 60%, шалгалтын ойролцоо оноо ≈18.0/30. Сул сэдэв: Удамшил 40%",
        "",
        "≈ тэмдэгтэй оноо нь сорилын хувиар бодсон ойролцоо тооцоо. Жинхэнэ шалгалтын оноо биш.",
    ]
    # Bold has no weak topic, so he is not listed.
    assert "Болд" not in messenger.last().text
    assert [recipient for recipient, _ in messenger.sent] == [TEACHER]
    assert "send_reminders" not in moodle.calls
    # Names, codes and marks of students stay out of the log.
    for private in ("B2009", "Саруул", "Ану", "41.7", "18.0"):
        assert private not in caplog.text


def test_quiz_choice_offers_only_quizzes_with_finished_attempts(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)

    run(bot.handle(tap(TEACHER, "QUIZ_COURSE:12", "k1")))

    reply = messenger.last()
    assert reply.text.split("\n") == [
        "«Биологи 101» хичээлийн сорилууд:",
        "1. Сорил 1: 3 оюутан өгсөн",
        "2. Эцсийн сорил: 2 оюутан өгсөн",
        "",
        "Аль сорилын дүнг харах вэ?",
    ]
    assert reply.quick_replies == [("1. Сорил 1", "QUIZ_SHOW:345"), ("2. Эцсийн сорил", "QUIZ_SHOW:347"),
                                   ("Болих", "CANCEL")]
    assert "Сорил 2" not in reply.text
    assert "get_quiz_topic_results" not in moodle.calls


def test_quiz_choice_with_one_quiz_or_none(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)
    moodle.courses[9].append(course(13, "Хими", quizzes=[quiz(400, "Хими 1", finished=0)]))
    moodle.courses[9].append(course(14, "Физик", quizzes=[quiz(500, "Хөдөлгөөн", finished=4), quiz(501, "Хүч", finished=0)]))
    moodle.quiz_results[500] = {"students": [quiz_student("B200910001", "Ану Мөнх", 90.0, {"Хурд": 90})]}

    # No quiz with a finished attempt.
    run(bot.handle(tap(TEACHER, "QUIZ_COURSE:13", "k1")))
    assert messenger.last().text == "«Хими» хичээлд оюутан өгсөн сорил одоогоор алга."

    # Only one such quiz: its results come at once, without a question.
    run(bot.handle(tap(TEACHER, "QUIZ_COURSE:14", "k2")))
    assert messenger.last().text.startswith("«Хөдөлгөөн» (Физик): 1 оюутан өгсөн, 0 оюутан өгөөгүй.")

    # A course that is not in the teacher's list.
    run(bot.handle(tap(TEACHER, "QUIZ_COURSE:99", "k3")))
    assert messenger.last().text == texts.COURSE_GONE


def test_quiz_results_without_weak_students_and_with_answers_that_wait_for_a_mark(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)
    essay_waits = quiz_student("B200910002", "Болд Ганзориг", 85.0, {"Эс": 90, "Эсээ": 0}, ungraded=1)
    essay_waits["topics"][1]["maxmark"] = 0
    moodle.quiz_results[347] = {
        "notattempted": 0,
        "topics": [{"name": "Эс", "maxmark": 10, "average": 90.0}, {"name": "Эсээ", "maxmark": 10, "average": 0.0}],
        "students": [essay_waits],
    }

    run(bot.handle(tap(TEACHER, "QUIZ_SHOW:347", "k1")))

    lines = messenger.last().text.split("\n")
    # The essay topic has no marked question yet, so it does not make the student weak.
    assert "60%-иас доош сэдэвтэй оюутан алга." in lines
    assert "Болд" not in messenger.last().text
    assert "≈" not in messenger.last().text
    assert lines[-1] == texts.QUIZ_UNGRADED_NOTE


def test_quiz_results_stop_at_fifteen_students_and_follow_the_settings(setup):
    _, moodle, messenger = setup
    biology_with_quizzes(moodle)
    moodle.quiz_results[345] = {
        "notattempted": 0,
        "topics": [{"name": "Эс", "maxmark": 10, "average": 45.0}],
        "students": [quiz_student(f"B2009100{number:02d}", f"Оюутан {number:02d}", 30.0 + number, {"Эс": 30 + number})
                     for number in range(1, 21)],
    }
    bot = Bot(moodle, messenger, clock=lambda: NOW, weak_topic_below=48, exam_points=40)

    run(bot.handle(tap(TEACHER, "QUIZ_SHOW:345", "k1")))

    lines = messenger.last().text.split("\n")
    # Students 1 to 17 are below 48 percent. The weakest come first.
    assert "48%-иас доош сэдэвтэй оюутнууд:" in lines
    listed = [line for line in lines if line.startswith("• B2009")]
    assert len(listed) == 15
    assert listed[0] == "• B200910001 Оюутан 01: сорил 31%, шалгалтын ойролцоо оноо ≈12.4/40. Сул сэдэв: Эс 31%"
    assert listed[-1].startswith("• B200910015 Оюутан 15:")
    assert "…бас 2 оюутан байна." in lines


def test_quiz_results_when_nobody_attempted(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)

    run(bot.handle(tap(TEACHER, "QUIZ_SHOW:346", "k1")))

    assert messenger.last().text == "«Сорил 2» (Биологи 101): одоогоор нэг ч оюутан өгөөгүй байна."


def test_quiz_results_are_only_for_teachers_of_the_course(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)

    # A student who sends the payloads gets nothing, and Moodle is not asked for teacher data.
    run(bot.handle(tap(STUDENT, "QUIZ_SHOW:345", "k1")))
    assert messenger.last().text == texts.NO_PERMISSION
    run(bot.handle(tap(STUDENT, "QUIZ_COURSE:12", "k2")))
    assert messenger.last().text == texts.NO_PERMISSION
    assert "get_quiz_topic_results" not in moodle.calls
    assert "get_courses" not in moodle.calls

    # A teacher asking about a quiz they may not see is refused by Moodle.
    run(bot.handle(tap(TEACHER, "QUIZ_SHOW:999", "k3")))
    assert messenger.last().text == texts.NO_PERMISSION
    assert "Саруул" not in " ".join(messenger.texts())


# Choosing the course.


def test_teacher_without_a_course_is_told_so(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = []

    for number, report in enumerate(("INACTIVE", "QUIZ")):
        run(bot.handle(tap(TEACHER, report, f"k{number}")))
        assert messenger.last().text == "Moodle дээр таны заадаг хичээл олдсонгүй."
    assert "get_inactive_students" not in moodle.calls
    assert "get_quiz_topic_results" not in moodle.calls


def test_teacher_with_one_course_is_not_asked_which_course(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)
    moodle.inactive[12] = {"students": [inactive_student("B200910003", "Саруул Тэмүүлэн", dayssince=-1)]}

    run(bot.handle(tap(TEACHER, "INACTIVE", "k1")))
    assert messenger.last().text.startswith("«Биологи 101»: идэвхгүй 1 оюутан")
    assert len(messenger.sent) == 1

    # The quiz report goes straight to the choice of a quiz.
    run(bot.handle(tap(TEACHER, "QUIZ", "k2")))
    assert messenger.last().text.startswith("«Биологи 101» хичээлийн сорилууд:")
    assert len(messenger.sent) == 2


def test_teacher_with_several_courses_picks_one(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)
    moodle.courses[9].append(course(13, "Хими", students=31))

    run(bot.handle(tap(TEACHER, "INACTIVE", "k1")))
    reply = messenger.last()
    assert reply.text.split("\n") == [
        "Таны хичээлүүд:",
        "1. Биологи 101 (5 оюутан)",
        "2. Хими (31 оюутан)",
        "",
        "Аль хичээлийг харах вэ?",
    ]
    assert reply.quick_replies == [("1. Биологи 101", "INACTIVE_COURSE:12"), ("2. Хими", "INACTIVE_COURSE:13"),
                                   ("Болих", "CANCEL")]
    assert "get_inactive_students" not in moodle.calls

    # The same list leads to the quiz report when that was asked for.
    run(bot.handle(tap(TEACHER, "QUIZ", "k2")))
    assert [payload for _, payload in messenger.last().quick_replies] == ["QUIZ_COURSE:12", "QUIZ_COURSE:13", "CANCEL"]

    # Tapping a course continues the report.
    run(bot.handle(tap(TEACHER, "INACTIVE_COURSE:13", "k3")))
    assert messenger.last().text == "«Хими»: сүүлийн 7 хоногт бүх оюутан орж ирсэн байна."


def test_long_course_list_shows_ten_courses(setup):
    bot, moodle, messenger = setup
    moodle.courses[9] = [course(100 + number, f"Хичээл {number:02d}") for number in range(1, 13)]

    run(bot.handle(tap(TEACHER, "QUIZ", "k1")))

    reply = messenger.last()
    assert "10. Хичээл 10 (0 оюутан)" in reply.text
    assert "Хичээл 11" not in reply.text
    assert "…бас 2 хичээл байна." in reply.text
    assert len(reply.quick_replies) == 11


def test_student_who_taps_a_report_gets_their_own_plan(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)

    for number, report in enumerate(("INACTIVE", "QUIZ")):
        run(bot.handle(tap(STUDENT, report, f"k{number}")))
        assert messenger.last().text.startswith("Ойрын 7 хоногт хийх ажил алга")
    assert "get_courses" not in moodle.calls
    assert moodle.calls.count("get_deadlines") == 2


# Asking in words.


def test_new_intents_and_the_old_ones():
    assert intent("идэвхгүй оюутнууд") == "INACTIVE"
    assert intent("Идэвхгүй оюутан хэн байна?") == "INACTIVE"
    assert intent("idevhgui oyutan") == "INACTIVE"
    assert intent("inactive students") == "INACTIVE"
    assert intent("Сорил 1-ийн дүн") == "QUIZ"
    assert intent("сул сэдэв") == "QUIZ"
    assert intent("soril 2") == "QUIZ"
    assert intent("Quiz results") == "QUIZ"
    # Both a report and PLAN match here. The report wins.
    assert intent("Сорилын хугацаа хэзээ вэ?") == "QUIZ"
    assert intent("идэвхгүй оюутны даалгавар") == "INACTIVE"

    # The phrases of the older intents still mean the same.
    assert intent("Энэ 7 хоногт юу хийх вэ?") == "PLAN"
    assert intent("hugatsaa heterseng haruul") == "PLAN"
    assert intent("7 хоногийн ажил") == "PLAN"
    assert intent("Хугацаа хэтэрсэн ажил") == "OVERDUE"
    assert intent("Хоцорсон ажил") == "OVERDUE"
    assert intent("хоцорсон сорил") == "OVERDUE"
    assert intent("илгээгээгүй хүмүүс") == "MISSING"
    assert intent("сануулга") == "MISSING"
    assert intent("салгах") == "UNLINK"
    assert intent("sain uu") == "HELP"
    assert intent("цаг агаар") == ""


def test_teacher_asks_for_the_reports_in_words(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)
    moodle.inactive[12] = {"students": [inactive_student("B200910003", "Саруул Тэмүүлэн", dayssince=-1)]}

    run(bot.handle(text(TEACHER, "идэвхгүй оюутнууд", "k1")))
    assert messenger.last().text.startswith("«Биологи 101»: идэвхгүй 1 оюутан")

    run(bot.handle(text(TEACHER, "Сорил 1-ийн дүн", "k2")))
    assert messenger.last().text.startswith("«Биологи 101» хичээлийн сорилууд:")


def test_student_who_asks_in_words_gets_no_teacher_data(setup):
    bot, moodle, messenger = setup
    biology_with_quizzes(moodle)
    moodle.links["psid-other-teacher"] = 9
    moodle.inactive[12] = {"students": [inactive_student("B200910003", "Саруул Тэмүүлэн", dayssince=-1)]}

    for number, words in enumerate(("идэвхгүй оюутнууд", "Сорил 1-ийн дүн", "сул сэдэв")):
        run(bot.handle(text(STUDENT, words, f"k{number}")))
        # The student gets the same answer as for their plan.
        assert messenger.last().text.startswith("Ойрын 7 хоногт хийх ажил алга")

    everything = " ".join(messenger.texts())
    for private in ("Саруул", "B2009", "Биологи 101", "Сорил 1"):
        assert private not in everything
    assert not {"get_courses", "get_inactive_students", "get_quiz_topic_results"} & set(moodle.calls)


# Menu and help.


def test_teachers_get_the_two_reports_in_menu_and_help(setup):
    bot, moodle, messenger = setup

    run(bot.handle(tap(TEACHER, "HELP", "k1")))
    reply = messenger.last()
    assert reply.quick_replies == [
        ("7 хоногийн ажил", "PLAN"),
        ("Хоцорсон ажил", "OVERDUE"),
        ("Илгээгээгүй", "MISSING"),
        ("Идэвхгүй оюутан", "INACTIVE"),
        ("Сорилын дүн", "QUIZ"),
        ("Тусламж", "HELP"),
    ]
    assert "• «Идэвхгүй оюутан»: хичээлдээ сүүлийн 7 хоногт ороогүй оюутнууд" in reply.text
    assert "• «Сорилын дүн»: сорилын дүн сэдэв тус бүрээр, сул сэдэвтэй оюутнууд" in reply.text

    # Students keep their short menu and help.
    run(bot.handle(tap(STUDENT, "HELP", "k2")))
    reply = messenger.last()
    assert [payload for _, payload in reply.quick_replies] == ["PLAN", "OVERDUE", "HELP"]
    assert "Идэвхгүй" not in reply.text
    assert "Сорилын дүн" not in reply.text


def test_quick_reply_titles_fit_in_twenty_characters(setup):
    bot, _, _ = setup
    titles = [title for title, _ in bot.menu({"isteacher": True})]
    titles += [texts.YES_SEND, texts.YES_UNLINK, texts.NO]
    assert all(len(title) <= 20 for title in titles), titles
    # The menu words lead to the reports when a teacher types them instead of tapping.
    assert intent(texts.MENU_INACTIVE) == "INACTIVE"
    assert intent(texts.MENU_QUIZ) == "QUIZ"


# Release.


def test_release_is_0_3_0_and_the_readme_names_what_is_new():
    from pathlib import Path

    import darimaa_bot

    assert darimaa_bot.__version__ == "0.3.0"
    readme = (Path(__file__).resolve().parent.parent / "README.md").read_text(encoding="utf-8")
    for needed in ("0.3.0", "WEAK_TOPIC_BELOW", "EXAM_POINTS", texts.MENU_INACTIVE, texts.MENU_QUIZ,
                   "local_darimaa_get_inactive_students", "local_darimaa_get_quiz_topic_results"):
        assert needed in readme, needed
