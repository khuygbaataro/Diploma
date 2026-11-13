"""Tests for the Moodle client: what it sends to the web service and how it reads errors."""

import asyncio
from urllib.parse import parse_qs

import httpx
import pytest

from darimaa_bot.moodle import MoodleClient, MoodleError

from .fakes import FakeMoodle, course, quiz


def call(method: str, *args, reply=None, **kwargs):
    """Calls one client method against a fake Moodle and returns (result, the form that was sent)."""
    sent = {}

    def handler(request: httpx.Request) -> httpx.Response:
        assert str(request.url) == "https://moodle.test/webservice/rest/server.php"
        sent.update({key: value[0] for key, value in parse_qs(request.content.decode()).items()})
        return httpx.Response(200, json=reply if reply is not None else {"linked": True})

    async def run():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as http:
            client = MoodleClient("https://moodle.test/", "moodle-token", "messenger", http)
            return await getattr(client, method)(*args, **kwargs)

    return asyncio.run(run()), sent


def test_teacher_report_functions_send_the_right_parameters():
    result, sent = call("get_courses", "psid-t", reply={"linked": True, "courses": []})
    assert result == {"linked": True, "courses": []}
    assert sent == {
        "wstoken": "moodle-token",
        "wsfunction": "local_darimaa_get_courses",
        "moodlewsrestformat": "json",
        "channel": "messenger",
        "externalid": "psid-t",
    }

    _, sent = call("get_inactive_students", "psid-t", 12)
    assert sent["wsfunction"] == "local_darimaa_get_inactive_students"
    assert (sent["externalid"], sent["courseid"], sent["days"]) == ("psid-t", "12", "7")
    _, sent = call("get_inactive_students", "psid-t", 12, days=14)
    assert sent["days"] == "14"

    _, sent = call("get_quiz_topic_results", "psid-t", 345)
    assert sent["wsfunction"] == "local_darimaa_get_quiz_topic_results"
    assert (sent["channel"], sent["externalid"], sent["cmid"]) == ("messenger", "psid-t", "345")


def test_moodle_errors_are_raised_with_their_code():
    reply = {"exception": "required_capability_exception", "errorcode": "nopermissions", "message": "No permission"}
    with pytest.raises(MoodleError) as error:
        call("get_quiz_topic_results", "psid-t", 345, reply=reply)
    assert error.value.errorcode == "nopermissions"
    assert error.value.message == "No permission"


def test_fakes_answer_like_the_plugin():
    moodle = FakeMoodle()
    moodle.links["psid-t"] = 9
    moodle.courses[9] = [course(12, "Биологи 101", students=3, quizzes=[quiz(345, "Эс", finished=2)])]

    courses = asyncio.run(moodle.get_courses("psid-t"))
    assert list(courses) == ["linked", "courses"]
    assert list(courses["courses"][0]) == ["id", "fullname", "shortname", "students", "quizzes"]
    assert list(courses["courses"][0]["quizzes"][0]) == ["cmid", "name", "timeclose", "finished"]

    report = asyncio.run(moodle.get_inactive_students("psid-t", 12))
    assert list(report) == ["linked", "coursename", "days", "lastaccessavailable", "timeavailable", "students"]
    assert (report["coursename"], report["days"]) == ("Биологи 101", 7)

    results = asyncio.run(moodle.get_quiz_topic_results("psid-t", 345))
    assert list(results) == ["linked", "quizname", "coursename", "topicsource", "notattempted", "topics", "students"]
    assert (results["quizname"], results["coursename"]) == ("Эс", "Биологи 101")

    # Like Moodle, the fake refuses a course or quiz the user does not teach.
    for refused in (moodle.get_inactive_students("psid-t", 99), moodle.get_quiz_topic_results("psid-t", 99)):
        with pytest.raises(MoodleError) as error:
            asyncio.run(refused)
        assert error.value.errorcode == "nopermissions"
    assert asyncio.run(moodle.get_courses("psid-unknown")) == {"linked": False, "courses": []}
