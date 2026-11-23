"""Tests for the Telegram connection, with Moodle and Telegram replaced by a fake transport."""

import asyncio
import json
import logging
from urllib.parse import parse_qs

import httpx
import pytest
from fastapi.testclient import TestClient

from darimaa_bot import texts
from darimaa_bot.app import create_app
from darimaa_bot.config import Settings
from darimaa_bot.conversation import Bot
from darimaa_bot.messenger import Reply
from darimaa_bot.setup_telegram import connect
from darimaa_bot.telegram import TelegramClient, TelegramError, callback_ids, parse_update, verify_secret

from .fakes import FakeMoodle

BOT_TOKEN = "123456:telegram-bot-token"
SECRET = "a-long-telegram-secret"
SETTINGS = Settings(
    moodle_url="https://moodle.test",
    moodle_token="moodle-token",
    telegram_token=BOT_TOKEN,
    telegram_secret=SECRET,
)


def message(text: str, chat_type: str = "private", update_id: int = 1) -> dict:
    return {"update_id": update_id, "message": {
        "message_id": 5, "from": {"id": 777}, "chat": {"id": 777, "type": chat_type}, "text": text,
    }}


def tap(data: str, update_id: int = 2) -> dict:
    return {"update_id": update_id, "callback_query": {
        "id": "cb-1", "from": {"id": 777}, "data": data, "message": {"chat": {"id": 777, "type": "private"}},
    }}


# Reading updates.


def test_updates_become_events():
    [event] = parse_update(message("Сайн уу"))
    assert (event.sender_id, event.kind, event.text, event.key) == ("777", "text", "Сайн уу", "tg:1")

    # The link from Moodle opens the bot with "/start CODE".
    [event] = parse_update(message("/start AB23CD45"))
    assert (event.kind, event.ref) == ("referral", "AB23CD45")
    [event] = parse_update(message("/start"))
    assert (event.kind, event.payload) == ("postback", "GET_STARTED")
    [event] = parse_update(message("/help@school_helper_bot"))
    assert event.payload == "HELP"

    [event] = parse_update(tap("ATTENTION_COURSE:12"))
    assert (event.sender_id, event.kind, event.payload, event.key) == ("777", "postback", "ATTENTION_COURSE:12", "tg:2")
    assert callback_ids(tap("PLAN")) == ["cb-1"]
    assert callback_ids(message("hi")) == []

    # A photo or sticker has no text.
    photo = message("")
    assert parse_update(photo)[0].kind == "other"


def test_group_chats_are_never_answered():
    # A teacher's report must not reach the other people in a group.
    assert parse_update(message("анхаарах оюутан", chat_type="group")) == []
    assert parse_update(message("hi", chat_type="supergroup")) == []
    group_tap = tap("PLAN")
    group_tap["callback_query"]["message"]["chat"]["type"] = "group"
    assert parse_update(group_tap) == []
    assert parse_update({"update_id": 3, "edited_message": {}}) == []


def test_secret_check():
    assert verify_secret(SECRET, SECRET)
    assert not verify_secret(SECRET, "wrong")
    assert not verify_secret(SECRET, None)
    assert not verify_secret("", "")


# Sending.


def run_client(handler, method: str, *args):
    async def go():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as http:
            client = TelegramClient("https://api.telegram.org/", BOT_TOKEN, http)
            return await getattr(client, method)(*args)
    return asyncio.run(go())


def test_replies_carry_the_buttons_as_an_inline_keyboard():
    sent = []

    def handler(request: httpx.Request) -> httpx.Response:
        assert str(request.url) == f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage"
        sent.append(json.loads(request.content))
        return httpx.Response(200, json={"ok": True, "result": {}})

    menu = [("7 хоногийн ажил", "PLAN"), ("Хоцорсон ажил", "OVERDUE"), ("Тусламж", "HELP")]
    run_client(handler, "send", "777", Reply("Сайн уу", quick_replies=menu))
    assert sent[-1] == {"chat_id": "777", "text": "Сайн уу", "reply_markup": {"inline_keyboard": [
        [{"text": "7 хоногийн ажил", "callback_data": "PLAN"}, {"text": "Хоцорсон ажил", "callback_data": "OVERDUE"}],
        [{"text": "Тусламж", "callback_data": "HELP"}],
    ]}}

    run_client(handler, "send", "777", Reply("Явуулах уу?", buttons=[("Тийм", "SEND:4"), ("Болих", "CANCEL")]))
    assert sent[-1]["reply_markup"] == {"inline_keyboard": [
        [{"text": "Тийм", "callback_data": "SEND:4"}, {"text": "Болих", "callback_data": "CANCEL"}],
    ]}

    # Long text is split, and only the last piece has the buttons.
    sent.clear()
    run_client(handler, "send", "777", Reply("\n".join(["мөр " * 50] * 40), quick_replies=menu))
    assert len(sent) == 2
    assert "reply_markup" not in sent[0] and "reply_markup" in sent[1]
    assert all(len(body["text"]) <= 4096 for body in sent)


def test_errors_never_show_the_bot_token(caplog):
    def refused(request: httpx.Request) -> httpx.Response:
        return httpx.Response(400, json={"ok": False, "description": "Bad Request: chat not found"})

    def down(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError(f"cannot reach {request.url}")

    with caplog.at_level(logging.DEBUG):
        for handler in (refused, down):
            with pytest.raises(TelegramError) as error:
                run_client(handler, "send", "777", Reply("hi"))
            assert BOT_TOKEN not in str(error.value)
            # The original httpx error, which names the address, is not shown with it.
            assert error.value.__context__ is None or error.value.__suppress_context__
    assert "chat not found" in caplog.text
    assert BOT_TOKEN not in caplog.text

    # Stopping the button spinner never fails the answer.
    assert run_client(refused, "answer_callback", "cb-1") is None


# The webhook.


def make_client(settings: Settings = SETTINGS):
    sent = []
    moodle_calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.host == "moodle.test":
            form = {k: v[0] for k, v in parse_qs(request.content.decode()).items()}
            moodle_calls.append(form)
            return httpx.Response(200, json={"linked": False, "linkurl": "https://moodle.test/local/darimaa/link.php"})
        if request.url.host == "api.telegram.org":
            sent.append((request.url.path.rsplit("/", 1)[-1], json.loads(request.content)))
            return httpx.Response(200, json={"ok": True, "result": True})
        return httpx.Response(404)

    return TestClient(create_app(settings, transport=httpx.MockTransport(handler))), sent, moodle_calls


def test_webhook_answers_with_the_secret_and_refuses_without(caplog):
    client, sent, moodle_calls = make_client()
    headers = {"X-Telegram-Bot-Api-Secret-Token": SECRET}
    with caplog.at_level(logging.DEBUG), client:
        assert client.post("/telegram/webhook", json=message("hi")).status_code == 403
        wrong = {"X-Telegram-Bot-Api-Secret-Token": "wrong-secret-value"}
        assert client.post("/telegram/webhook", json=message("hi"), headers=wrong).status_code == 403
        assert sent == []

        assert client.post("/telegram/webhook", json=message("hi"), headers=headers).status_code == 200
        assert client.post("/telegram/webhook", json=tap("PLAN"), headers=headers).status_code == 200
        assert client.post("/telegram/webhook", content=b"[1]", headers=headers).status_code == 400
        # Messenger is not set up, so its webhook does not exist.
        assert client.get("/webhook").status_code == 404

    # Moodle is asked with the Telegram channel, and the user is told how to connect Telegram.
    assert moodle_calls[0]["channel"] == "telegram"
    assert moodle_calls[0]["externalid"] == "777"
    method, body = sent[0]
    assert method == "sendMessage"
    assert body["chat_id"] == "777"
    assert "«Connect Telegram»" in body["text"]
    # The tapped button is answered, so its spinner stops.
    assert ("answerCallbackQuery", {"callback_query_id": "cb-1"}) in sent
    for secret in (BOT_TOKEN, SECRET, "moodle-token"):
        assert secret not in caplog.text


def test_telegram_webhook_does_not_exist_without_telegram():
    settings = Settings(moodle_url="https://moodle.test", moodle_token="moodle-token",
                        page_access_token="page-token", app_secret="app-secret", verify_token="verify-me")
    client, _, _ = make_client(settings)
    with client:
        assert client.post("/telegram/webhook", json=message("hi"),
                           headers={"X-Telegram-Bot-Api-Secret-Token": SECRET}).status_code == 404


# The conversation names the chat app.


def test_linking_in_telegram_names_telegram():
    moodle = FakeMoodle()
    sent = []

    class Chat:
        async def send(self, recipient_id, reply):
            sent.append(reply.text)

    bot = Bot(moodle, Chat(), app_name="Telegram")
    for event in parse_update(message("/start AB23CD45")):
        asyncio.run(bot.handle(event))
    assert sent[0] == texts.LINK_OK.format(firstname="Anu", app="Telegram")
    assert "Telegram" in sent[0]


# Setting up the webhook.


def test_connect_sets_the_webhook_and_commands():
    calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        method = request.url.path.rsplit("/", 1)[-1]
        calls.append((method, json.loads(request.content)))
        if method == "getMe":
            return httpx.Response(200, json={"ok": True, "result": {"username": "school_helper_bot"}})
        return httpx.Response(200, json={"ok": True, "result": True})

    async def go():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as http:
            return await connect(SETTINGS, "https://moodle.test/darimaa-bot/telegram/webhook", http)

    assert asyncio.run(go()) == "school_helper_bot"
    assert [method for method, _ in calls] == ["getMe", "setWebhook", "setMyCommands"]
    webhook = calls[1][1]
    assert webhook["url"] == "https://moodle.test/darimaa-bot/telegram/webhook"
    assert webhook["secret_token"] == SECRET
    assert webhook["allowed_updates"] == ["message", "callback_query"]
    assert calls[2][1]["commands"][0] == {"command": "start", "description": "Эхлэх"}

    with pytest.raises(ValueError):
        asyncio.run(connect(SETTINGS, "http://moodle.test/telegram/webhook", None))
