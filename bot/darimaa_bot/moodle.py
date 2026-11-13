"""Talks to Moodle through the web service functions of the local_darimaa plugin."""

from __future__ import annotations

from typing import Any

import httpx


class MoodleError(Exception):
    """Moodle answered with an error, for example a missing permission."""

    def __init__(self, errorcode: str, message: str) -> None:
        super().__init__(f"{errorcode}: {message}")
        self.errorcode = errorcode
        self.message = message


class MoodleClient:
    """Calls Moodle's REST API with the bot's token, always naming the chat account."""

    def __init__(self, base_url: str, token: str, channel: str, http: httpx.AsyncClient) -> None:
        self.endpoint = f"{base_url.rstrip('/')}/webservice/rest/server.php"
        self.token = token
        self.channel = channel
        self.http = http

    async def call(self, function: str, **params: Any) -> Any:
        data = {"wstoken": self.token, "wsfunction": function, "moodlewsrestformat": "json"}
        for key, value in params.items():
            data[key] = str(int(value)) if isinstance(value, bool) else str(value)
        response = await self.http.post(self.endpoint, data=data, timeout=30)
        response.raise_for_status()
        result = response.json()
        if isinstance(result, dict) and "exception" in result:
            raise MoodleError(str(result.get("errorcode", "")), str(result.get("message", "")))
        return result

    async def get_user(self, externalid: str) -> dict[str, Any]:
        return await self.call("local_darimaa_get_user", channel=self.channel, externalid=externalid)

    async def link_account(self, externalid: str, code: str) -> dict[str, Any]:
        return await self.call("local_darimaa_link_account", channel=self.channel, externalid=externalid, code=code)

    async def unlink_account(self, externalid: str) -> dict[str, Any]:
        return await self.call("local_darimaa_unlink_account", channel=self.channel, externalid=externalid)

    async def get_deadlines(self, externalid: str, days: int = 7, overduedays: int = 14) -> dict[str, Any]:
        return await self.call(
            "local_darimaa_get_deadlines",
            channel=self.channel,
            externalid=externalid,
            days=days,
            overduedays=overduedays,
        )

    async def get_missing(self, externalid: str, pastdays: int = 30, days: int = 7) -> dict[str, Any]:
        return await self.call(
            "local_darimaa_get_missing",
            channel=self.channel,
            externalid=externalid,
            pastdays=pastdays,
            days=days,
        )

    async def send_reminders(self, externalid: str, cmid: int) -> dict[str, Any]:
        return await self.call(
            "local_darimaa_send_reminders",
            channel=self.channel,
            externalid=externalid,
            cmid=cmid,
        )

    # The three functions below need plugin 0.3.0 or later. They only read data.

    async def get_courses(self, externalid: str) -> dict[str, Any]:
        """A teacher's courses with their quizzes."""
        return await self.call("local_darimaa_get_courses", channel=self.channel, externalid=externalid)

    async def get_inactive_students(self, externalid: str, courseid: int, days: int = 7) -> dict[str, Any]:
        """Last access, time spent and missing work of the students of one course."""
        return await self.call(
            "local_darimaa_get_inactive_students",
            channel=self.channel,
            externalid=externalid,
            courseid=courseid,
            days=days,
        )

    async def get_quiz_topic_results(self, externalid: str, cmid: int) -> dict[str, Any]:
        """The results of one quiz by topic."""
        return await self.call(
            "local_darimaa_get_quiz_topic_results",
            channel=self.channel,
            externalid=externalid,
            cmid=cmid,
        )
