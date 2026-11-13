"""Tests for the webhook endpoints, with Moodle and Meta replaced by a fake transport."""

import hashlib
import hmac
import json
import logging
from urllib.parse import parse_qs

import httpx
import pytest
from fastapi.testclient import TestClient

from darimaa_bot.app import create_app
from darimaa_bot.config import Settings

SETTINGS = Settings(
    moodle_url="https://moodle.test",
    moodle_token="moodle-token",
    page_access_token="page-token",
    app_secret="app-secret",
    verify_token="verify-me",
)


def make_client(graph_status: int = 200):
    sent = []
    moodle_calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.host == "moodle.test":
            form = {k: v[0] for k, v in parse_qs(request.content.decode()).items()}
            moodle_calls.append(form)
            assert form["wstoken"] == "moodle-token"
            if form["wsfunction"] == "local_darimaa_get_user":
                return httpx.Response(200, json={"linked": False, "linkurl": "https://moodle.test/local/darimaa/link.php"})
            return httpx.Response(200, json={"exception": "x", "errorcode": "unexpected", "message": "unexpected"})
        if request.url.host == "graph.facebook.com":
            assert request.headers["authorization"] == "Bearer page-token"
            assert "access_token" not in request.url.params
            sent.append(json.loads(request.content))
            if graph_status >= 400:
                return httpx.Response(graph_status, json={"error": {"message": "refused"}})
            return httpx.Response(200, json={"message_id": "mid"})
        return httpx.Response(404)

    app = create_app(SETTINGS, transport=httpx.MockTransport(handler))
    return TestClient(app), sent, moodle_calls


def signed(body: dict) -> tuple[bytes, dict]:
    raw = json.dumps(body).encode()
    signature = "sha256=" + hmac.new(b"app-secret", raw, hashlib.sha256).hexdigest()
    return raw, {"X-Hub-Signature-256": signature, "Content-Type": "application/json"}


def test_webhook_verification():
    client, _, _ = make_client()
    with client:
        ok = client.get("/webhook", params={"hub.mode": "subscribe", "hub.verify_token": "verify-me", "hub.challenge": "42"})
        assert ok.status_code == 200 and ok.text == "42"
        bad = client.get("/webhook", params={"hub.mode": "subscribe", "hub.verify_token": "nope", "hub.challenge": "42"})
        assert bad.status_code == 403
        assert client.get("/healthz").json() == {"ok": True}


def test_signed_message_gets_a_reply_and_unsigned_is_refused():
    client, sent, moodle_calls = make_client()
    body = {"object": "page", "entry": [{"messaging": [
        {"sender": {"id": "psid-1"}, "timestamp": 1, "message": {"mid": "m1", "text": "сайн уу"}},
    ]}]}
    raw, headers = signed(body)
    with client:
        assert client.post("/webhook", content=raw, headers={"Content-Type": "application/json"}).status_code == 403
        assert sent == []

        response = client.post("/webhook", content=raw, headers=headers)
        assert response.status_code == 200

    assert moodle_calls[0]["wsfunction"] == "local_darimaa_get_user"
    assert moodle_calls[0]["externalid"] == "psid-1"
    assert sent[0]["recipient"] == {"id": "psid-1"}
    assert sent[0]["messaging_type"] == "RESPONSE"
    assert "local/darimaa/link.php" in sent[0]["message"]["text"]


@pytest.mark.parametrize("graph_status", [200, 400])
def test_tokens_never_reach_the_log(caplog, graph_status):
    client, sent, _ = make_client(graph_status)
    body = {"object": "page", "entry": [{"messaging": [
        {"sender": {"id": "psid-1"}, "timestamp": 1, "message": {"mid": "m1", "text": "сайн уу"}},
    ]}]}
    raw, headers = signed(body)
    with caplog.at_level(logging.DEBUG), client:
        assert client.post("/webhook", content=raw, headers=headers).status_code == 200

    # The request to Meta was made and logged, whether Meta accepted it or not.
    assert sent
    assert "graph.facebook.com" in caplog.text
    for secret in ("page-token", "moodle-token", "app-secret"):
        assert secret not in caplog.text
