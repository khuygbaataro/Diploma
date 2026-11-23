"""Telegram Bot API: checking webhook calls, reading updates and sending replies.

The bot token is part of every Bot API address, so addresses must never reach a log or an error
message. Errors are raised as TelegramError, whose text holds the method name only.
"""

from __future__ import annotations

import hmac
import logging
import re
from typing import Any

import httpx

from .messenger import Incoming, Reply, split_text

log = logging.getLogger(__name__)

TEXT_LIMIT = 4096
SECRET_PATTERN = re.compile(r"^[A-Za-z0-9_-]{16,256}$")
# Commands the bot understands. "/start CODE" comes from the link that Moodle shows with the code.
COMMANDS = {"start": "GET_STARTED", "help": "HELP", "menu": "HELP"}


class TelegramError(httpx.HTTPError):
    """Telegram could not be reached or refused a request. The text never holds the bot token."""


def verify_secret(expected: str, header: str | None) -> bool:
    """Checks the secret token Telegram sends with every webhook call."""
    return bool(expected) and bool(header) and hmac.compare_digest(expected, header or "")


def parse_update(data: dict[str, Any]) -> list[Incoming]:
    """Turns one webhook update into the events the bot answers. Only private chats are answered.

    A group chat is never answered: a teacher's report must not reach other people in a group.
    """
    key = f"tg:{data.get('update_id', '')}"

    query = data.get("callback_query")
    if isinstance(query, dict):
        chat = (query.get("message") or {}).get("chat") or {}
        sender = str((query.get("from") or {}).get("id") or "")
        if not sender or chat.get("type", "private") != "private":
            return []
        return [Incoming(sender, "postback", payload=str(query.get("data") or ""), key=key)]

    message = data.get("message")
    if not isinstance(message, dict):
        return []
    chat = message.get("chat") or {}
    sender = str((message.get("from") or {}).get("id") or "")
    if not sender or chat.get("type") != "private":
        return []

    text = str(message.get("text") or "")
    if not text:
        return [Incoming(sender, "other", key=key)]
    if text.startswith("/"):
        command, _, argument = text[1:].partition(" ")
        command = command.split("@", 1)[0].lower()
        argument = argument.strip()
        if command == "start" and argument:
            return [Incoming(sender, "referral", ref=argument, key=key)]
        if command in COMMANDS:
            return [Incoming(sender, "postback", payload=COMMANDS[command], key=key)]
    return [Incoming(sender, "text", text=text, key=key)]


def callback_ids(data: dict[str, Any]) -> list[str]:
    """The ids of button taps in an update. Telegram shows a spinner on the button until it is answered."""
    query = data.get("callback_query")
    if isinstance(query, dict) and query.get("id"):
        return [str(query["id"])]
    return []


class TelegramClient:
    """Calls the Bot API with the bot token."""

    def __init__(self, api_url: str, token: str, http: httpx.AsyncClient) -> None:
        self._base = f"{api_url.rstrip('/')}/bot{token}"
        self.http = http

    async def call(self, method: str, body: dict[str, Any]) -> Any:
        try:
            response = await self.http.post(f"{self._base}/{method}", json=body, timeout=30)
        except httpx.HTTPError as error:
            # The error of httpx can name the address, and the address holds the token.
            raise TelegramError(f"Telegram {method} failed: {type(error).__name__}") from None
        try:
            data = response.json()
        except ValueError:
            data = {}
        if response.status_code >= 400 or not isinstance(data, dict) or not data.get("ok"):
            description = str(data.get("description", "")) if isinstance(data, dict) else ""
            log.error("Telegram refused %s: %s %s", method, response.status_code, description[:300])
            raise TelegramError(f"Telegram refused {method}: {response.status_code}")
        return data.get("result")

    async def send(self, recipient_id: str, reply: Reply) -> None:
        if reply.buttons:
            rows = [[self._button(title, payload) for title, payload in reply.buttons[:3]]]
        else:
            rows = [[self._button(title, payload) for title, payload in reply.quick_replies[i:i + 2]]
                    for i in range(0, len(reply.quick_replies), 2)]
        pieces = split_text(reply.text, TEXT_LIMIT)
        for index, piece in enumerate(pieces):
            body: dict[str, Any] = {"chat_id": recipient_id, "text": piece}
            if rows and index == len(pieces) - 1:
                body["reply_markup"] = {"inline_keyboard": rows}
            await self.call("sendMessage", body)

    async def typing(self, recipient_id: str) -> None:
        """Shows "typing…" in the chat. Failures are ignored: it is only a courtesy."""
        try:
            await self.call("sendChatAction", {"chat_id": recipient_id, "action": "typing"})
        except TelegramError:
            pass

    async def answer_callback(self, callback_id: str) -> None:
        """Stops the spinner on a tapped button. Failures are ignored: it is only a courtesy."""
        try:
            await self.call("answerCallbackQuery", {"callback_query_id": callback_id})
        except TelegramError:
            pass

    async def get_me(self) -> dict[str, Any]:
        return await self.call("getMe", {})

    async def set_webhook(self, url: str, secret: str) -> None:
        await self.call("setWebhook", {
            "url": url,
            "secret_token": secret,
            "allowed_updates": ["message", "callback_query"],
            "drop_pending_updates": True,
        })

    async def set_commands(self, commands: list[tuple[str, str]]) -> None:
        await self.call("setMyCommands", {
            "commands": [{"command": name, "description": description} for name, description in commands],
        })

    @staticmethod
    def _button(title: str, payload: str) -> dict[str, str]:
        # Telegram allows at most 64 bytes of callback data. The bot's payloads are far shorter.
        return {"text": title, "callback_data": payload[:64]}
