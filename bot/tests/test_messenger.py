"""Tests for webhook signatures, event parsing and text splitting."""

import hashlib
import hmac

from darimaa_bot.messenger import parse_events, split_text, verify_signature


def sign(secret: str, body: bytes) -> str:
    return "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()


def test_signature_must_match_the_body():
    body = b'{"object":"page"}'
    assert verify_signature("secret", body, sign("secret", body))
    assert not verify_signature("secret", body + b" ", sign("secret", body))
    assert not verify_signature("other", body, sign("secret", body))
    assert not verify_signature("secret", body, None)
    assert not verify_signature("secret", body, "sha1=abc")


def test_parse_events_reads_each_kind_and_skips_echoes():
    data = {
        "object": "page",
        "entry": [{
            "messaging": [
                {"sender": {"id": "1"}, "timestamp": 1, "message": {"mid": "m1", "text": "сайн уу"}},
                {"sender": {"id": "1"}, "timestamp": 2, "message": {"mid": "m2", "text": "7 хоногийн ажил",
                                                                     "quick_reply": {"payload": "PLAN"}}},
                {"sender": {"id": "1"}, "timestamp": 3, "postback": {"payload": "GET_STARTED",
                                                                      "referral": {"ref": "AB23CD45"}}},
                {"sender": {"id": "1"}, "timestamp": 4, "referral": {"ref": "ZX98YW76", "source": "SHORTLINK"}},
                {"sender": {"id": "page"}, "timestamp": 5, "message": {"mid": "m5", "text": "echo", "is_echo": True}},
                {"sender": {"id": "1"}, "timestamp": 6, "message": {"mid": "m6", "attachments": [{"type": "image"}]}},
                {"sender": {"id": "1"}, "timestamp": 7, "delivery": {"mids": ["m1"]}},
            ]
        }],
    }
    events = parse_events(data)
    assert [(e.kind, e.text, e.payload, e.ref) for e in events] == [
        ("text", "сайн уу", "", ""),
        ("postback", "", "PLAN", ""),
        ("postback", "", "GET_STARTED", "AB23CD45"),
        ("referral", "", "", "ZX98YW76"),
        ("other", "", "", ""),
    ]
    assert events[0].key == "m1"
    assert len({e.key for e in events}) == len(events)


def test_parse_events_ignores_other_objects():
    assert parse_events({"object": "instagram", "entry": [{"messaging": [{"sender": {"id": "1"}}]}]}) == []


def test_split_text_keeps_pieces_under_the_limit():
    text = "\n".join(f"line {i} " + "x" * 50 for i in range(100))
    pieces = split_text(text, limit=500)
    assert all(len(p) <= 500 for p in pieces)
    assert "\n".join(pieces) == text
    assert split_text("short") == ["short"]
    assert all(len(p) <= 100 for p in split_text("y" * 250, limit=100))
