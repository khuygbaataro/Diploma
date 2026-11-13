"""In-memory stand-ins for Moodle and Messenger."""

from __future__ import annotations

from typing import Any

from darimaa_bot.messenger import Reply
from darimaa_bot.moodle import MoodleError


class FakeMoodle:
    """Behaves like the local_darimaa web service functions."""

    LINKURL = "https://moodle.test/local/darimaa/link.php"

    def __init__(self) -> None:
        self.users = {
            7: {"firstname": "Anu", "isteacher": False},
            9: {"firstname": "Bat", "isteacher": True},
        }
        self.codes = {"AB23CD45": 7, "TE4CHER9": 9}
        self.links: dict[str, int] = {}
        self.deadlines: dict[int, list[dict[str, Any]]] = {7: [], 9: []}
        self.missing: dict[int, list[dict[str, Any]]] = {9: []}
        self.sent: list[tuple[int, int]] = []
        self.calls: list[str] = []
        # Teacher reports (plugin 0.3.0): courses by user id, reports by course id and by quiz cmid.
        self.courses: dict[int, list[dict[str, Any]]] = {9: []}
        self.inactive: dict[int, dict[str, Any]] = {}
        self.quiz_results: dict[int, dict[str, Any]] = {}
        self.inactive_days: list[int] = []

    async def get_user(self, externalid: str) -> dict[str, Any]:
        self.calls.append("get_user")
        userid = self.links.get(externalid)
        if not userid:
            return {"linked": False, "firstname": "", "timezone": "", "isteacher": False, "linkurl": self.LINKURL}
        user = self.users[userid]
        return {"linked": True, "firstname": user["firstname"], "timezone": "Asia/Ulaanbaatar",
                "isteacher": user["isteacher"], "linkurl": self.LINKURL}

    async def link_account(self, externalid: str, code: str) -> dict[str, Any]:
        self.calls.append("link_account")
        userid = self.codes.pop(code.replace("-", "").upper(), None)
        if not userid:
            return {"linked": False, "firstname": ""}
        self.links[externalid] = userid
        return {"linked": True, "firstname": self.users[userid]["firstname"]}

    async def unlink_account(self, externalid: str) -> dict[str, Any]:
        self.calls.append("unlink_account")
        return {"unlinked": self.links.pop(externalid, None) is not None}

    async def get_deadlines(self, externalid: str, days: int = 7, overduedays: int = 14) -> dict[str, Any]:
        self.calls.append("get_deadlines")
        userid = self.links.get(externalid)
        return {"linked": bool(userid), "items": self.deadlines.get(userid, [])}

    async def get_missing(self, externalid: str, pastdays: int = 30, days: int = 7) -> dict[str, Any]:
        self.calls.append("get_missing")
        userid = self.links.get(externalid)
        return {"linked": bool(userid), "assignments": self.missing.get(userid, [])}

    async def send_reminders(self, externalid: str, cmid: int) -> dict[str, Any]:
        self.calls.append("send_reminders")
        userid = self.links.get(externalid)
        if not self.users.get(userid, {}).get("isteacher"):
            raise MoodleError("nopermissions", "Sorry, but you do not currently have permissions to do that")
        rows = [r for r in self.missing.get(userid, []) if r["cmid"] == cmid]
        count = rows[0]["missing"] if rows else 0
        self.sent.append((userid, cmid))
        return {"sent": count, "missing": count}

    async def get_courses(self, externalid: str) -> dict[str, Any]:
        self.calls.append("get_courses")
        userid = self.links.get(externalid)
        return {"linked": bool(userid), "courses": self.courses.get(userid, [])}

    async def get_inactive_students(self, externalid: str, courseid: int, days: int = 7) -> dict[str, Any]:
        self.calls.append("get_inactive_students")
        userid = self.links.get(externalid)
        names = {row["id"]: row["fullname"] for row in self.courses.get(userid, [])}
        if courseid not in names:
            raise MoodleError("nopermissions", "Sorry, but you do not currently have permissions to do that")
        self.inactive_days.append(days)
        return {"linked": True, "coursename": names[courseid], "days": days, "lastaccessavailable": True,
                "timeavailable": True, "students": []} | self.inactive.get(courseid, {})

    async def get_quiz_topic_results(self, externalid: str, cmid: int) -> dict[str, Any]:
        self.calls.append("get_quiz_topic_results")
        userid = self.links.get(externalid)
        names = {row["cmid"]: (row["name"], parent["fullname"])
                 for parent in self.courses.get(userid, []) for row in parent["quizzes"]}
        if cmid not in names:
            raise MoodleError("nopermissions", "Sorry, but you do not currently have permissions to do that")
        return {"linked": True, "quizname": names[cmid][0], "coursename": names[cmid][1],
                "topicsource": "categories", "notattempted": 0, "topics": [],
                "students": []} | self.quiz_results.get(cmid, {})


def course(courseid: int, fullname: str, students: int = 0, quizzes: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    """One row of get_courses."""
    return {"id": courseid, "fullname": fullname, "shortname": fullname[:3].upper(), "students": students,
            "quizzes": quizzes or []}


def quiz(cmid: int, name: str, finished: int, timeclose: int = 0) -> dict[str, Any]:
    """One quiz row of get_courses."""
    return {"cmid": cmid, "name": name, "timeclose": timeclose, "finished": finished}


def inactive_student(code: str, fullname: str, dayssince: int, minutes: int = 0, missing: int = 0,
                     inactive: bool = True) -> dict[str, Any]:
    """One student row of get_inactive_students. dayssince -1 means the student never came."""
    lastaccess = 0 if dayssince < 0 else 1790000000 - dayssince * 86400
    return {"code": code, "fullname": fullname, "lastaccess": lastaccess, "dayssince": dayssince,
            "minutes": minutes, "missing": missing, "inactive": inactive}


def quiz_student(code: str, fullname: str, percent: float, topics: dict[str, float], ungraded: int = 0) -> dict[str, Any]:
    """One student row of get_quiz_topic_results. Every topic is out of 10 marks."""
    return {"code": code, "fullname": fullname, "percent": percent, "ungraded": ungraded,
            "topics": [{"name": name, "mark": value / 10, "maxmark": 10, "percent": value}
                       for name, value in topics.items()]}


class FakeMessenger:
    """Collects what the bot would send."""

    def __init__(self) -> None:
        self.sent: list[tuple[str, Reply]] = []

    async def send(self, recipient_id: str, reply: Reply) -> None:
        self.sent.append((recipient_id, reply))

    def texts(self) -> list[str]:
        return [reply.text for _, reply in self.sent]

    def last(self) -> Reply:
        return self.sent[-1][1]
