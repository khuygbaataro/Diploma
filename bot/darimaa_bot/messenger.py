"""Messenger Platform: checking webhook signatures, reading events and sending replies."""

from __future__ import annotations

import hashlib
import hmac
import logging
from dataclasses import dataclass, field
from typing import Any

import httpx

log = logging.getLogger(__name__)

TEXT_LIMIT = 2000
BUTTON_TEXT_LIMIT = 640
TITLE_LIMIT = 20


def verify_signature(app_secret: str, body: bytes, header: str | None) -> bool:
    """Checks the X-Hub-Signature-256 header Meta adds to every webhook call."""
    if not header or not header.startswith("sha256="):
        return False
    expected = hmac.new(app_secret.encode("utf-8"), body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, header[len("sha256="):])


@dataclass
class Incoming:
    """One thing a person did in the chat."""

    sender_id: str
    kind: str  # text, postback, referral or other
    text: str = ""
    payload: str = ""
    ref: str = ""
    key: str = ""  # used to skip events Meta delivers twice


@dataclass
class Reply:
    """One message the bot sends back."""

    text: str
    quick_replies: list[tuple[str, str]] = field(default_factory=list)  # (title, payload)
    buttons: list[tuple[str, str]] = field(default_factory=list)  # (title, payload), at most 3


def parse_events(data: dict[str, Any]) -> list[Incoming]:
    """Turns a webhook body into the events the bot answers. Echoes and receipts are skipped."""
    events: list[Incoming] = []
    if data.get("object") != "page":
        return events

    for entry in data.get("entry", []) or []:
        for item in entry.get("messaging", []) or []:
            sender = str((item.get("sender") or {}).get("id") or "")
            if not sender:
                continue
            stamp = str(item.get("timestamp", ""))

            message = item.get("message")
            if message:
                if message.get("is_echo"):
                    continue
                key = str(message.get("mid") or f"{sender}:{stamp}")
                quick = message.get("quick_reply") or {}
                if quick.get("payload"):
                    events.append(Incoming(sender, "postback", payload=str(quick["payload"]), key=key))
                elif message.get("text"):
                    events.append(Incoming(sender, "text", text=str(message["text"]), key=key))
                else:
                    events.append(Incoming(sender, "other", key=key))
                continue

            postback = item.get("postback")
            if postback:
                payload = str(postback.get("payload", ""))
                ref = str((postback.get("referral") or {}).get("ref", ""))
                key = str(postback.get("mid") or f"{sender}:{stamp}:{payload}")
                events.append(Incoming(sender, "postback", payload=payload, ref=ref, key=key))
                continue

            referral = item.get("referral")
            if referral:
                events.append(Incoming(sender, "referral", ref=str(referral.get("ref", "")), key=f"{sender}:{stamp}:ref"))

    return events


def split_text(text: str, limit: int = TEXT_LIMIT) -> list[str]:
    """Splits long text into pieces Messenger accepts, at line breaks where possible."""
    pieces: list[str] = []
    current = ""
    for line in text.split("\n"):
        while len(line) > limit:
            if current:
                pieces.append(current)
                current = ""
            pieces.append(line[:limit])
            line = line[limit:]
        candidate = f"{current}\n{line}" if current else line
        if len(candidate) > limit:
            pieces.append(current)
            current = line
        else:
            current = candidate
    if current or not pieces:
        pieces.append(current)
    return pieces


class MessengerClient:
    """Sends replies with the Send API, using the Page access token."""

    def __init__(self, graph_url: str, version: str, page_token: str, http: httpx.AsyncClient) -> None:
        self.base = f"{graph_url.rstrip('/')}/{version}"
        self.page_token = page_token
        self.http = http

    async def _post(self, path: str, body: dict[str, Any]) -> None:
        response = await self.http.post(
            f"{self.base}/{path}",
            params={"access_token": self.page_token},
            json=body,
            timeout=30,
        )
        if response.status_code >= 400:
            log.error("Messenger refused %s: %s %s", path, response.status_code, response.text[:500])
            response.raise_for_status()

    async def send(self, recipient_id: str, reply: Reply) -> None:
        if reply.buttons:
            message: dict[str, Any] = {
                "attachment": {
                    "type": "template",
                    "payload": {
                        "template_type": "button",
                        "text": reply.text[:BUTTON_TEXT_LIMIT],
                        "buttons": [
                            {"type": "postback", "title": title[:TITLE_LIMIT], "payload": payload}
                            for title, payload in reply.buttons[:3]
                        ],
                    },
                }
            }
            await self._post("me/messages", self._envelope(recipient_id, message))
            return

        pieces = split_text(reply.text)
        for index, piece in enumerate(pieces):
            message = {"text": piece}
            if reply.quick_replies and index == len(pieces) - 1:
                message["quick_replies"] = [
                    {"content_type": "text", "title": title[:TITLE_LIMIT], "payload": payload}
                    for title, payload in reply.quick_replies[:13]
                ]
            await self._post("me/messages", self._envelope(recipient_id, message))

    async def typing(self, recipient_id: str) -> None:
        """Shows the typing dots. Failures are ignored: it is only a courtesy."""
        try:
            await self._post("me/messages", {"recipient": {"id": recipient_id}, "sender_action": "typing_on"})
        except httpx.HTTPError:
            pass

    async def set_profile(self, profile: dict[str, Any]) -> None:
        await self._post("me/messenger_profile", profile)

    @staticmethod
    def _envelope(recipient_id: str, message: dict[str, Any]) -> dict[str, Any]:
        return {"recipient": {"id": recipient_id}, "messaging_type": "RESPONSE", "message": message}
