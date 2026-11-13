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

    async def get_user(self, externalid: str) -> dict[str, Any]:
        self.calls.append("get_user")
        userid = self.links.get(externalid)
        if not userid:
            return {"linked": False, "userid": 0, "firstname": "", "isteacher": False,
                    "timezone": "", "linkurl": self.LINKURL}
        user = self.users[userid]
        return {"linked": True, "userid": userid, "firstname": user["firstname"], "isteacher": user["isteacher"],
                "timezone": "Asia/Ulaanbaatar", "linkurl": self.LINKURL}

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
