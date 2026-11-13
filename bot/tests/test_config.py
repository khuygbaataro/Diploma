"""Tests for reading the bot's settings."""

from pathlib import Path

import pytest

from darimaa_bot.config import Settings, load_env_file

BOT_ROOT = Path(__file__).resolve().parent.parent
REQUIRED = {
    "MOODLE_URL": "https://moodle.test/",
    "MOODLE_TOKEN": "moodle-token",
    "FB_PAGE_ACCESS_TOKEN": "page-token",
    "FB_APP_SECRET": "app-secret",
    "FB_VERIFY_TOKEN": "verify-me",
    "DARIMAA_ENV_FILE": "no-such-file.env",
}


def settings(monkeypatch, **extra) -> Settings:
    # The .env file of a developer must not leak into the tests.
    monkeypatch.setenv("DARIMAA_ENV_FILE", "no-such-file.env")
    return Settings.from_env(REQUIRED | extra)


def test_quiz_settings_have_defaults(monkeypatch):
    result = settings(monkeypatch)
    assert result.moodle_url == "https://moodle.test"
    assert result.weak_topic_below == 60.0
    assert result.exam_points == 30.0


def test_quiz_settings_come_from_the_environment(monkeypatch):
    result = settings(monkeypatch, WEAK_TOPIC_BELOW="50", EXAM_POINTS="40.5")
    assert result.weak_topic_below == 50.0
    assert result.exam_points == 40.5
    # An empty value means the default.
    assert settings(monkeypatch, WEAK_TOPIC_BELOW="", EXAM_POINTS=" ").exam_points == 30.0


@pytest.mark.parametrize("name, value", [
    ("WEAK_TOPIC_BELOW", "sixty"),
    ("WEAK_TOPIC_BELOW", "0"),
    ("WEAK_TOPIC_BELOW", "101"),
    ("EXAM_POINTS", "-5"),
    ("EXAM_POINTS", "many"),
])
def test_wrong_quiz_settings_are_refused(monkeypatch, name, value):
    with pytest.raises(RuntimeError, match=name):
        settings(monkeypatch, **{name: value})


def test_a_missing_required_setting_is_named(monkeypatch):
    monkeypatch.setenv("DARIMAA_ENV_FILE", "no-such-file.env")
    with pytest.raises(RuntimeError, match="MOODLE_TOKEN"):
        Settings.from_env({key: value for key, value in REQUIRED.items() if key != "MOODLE_TOKEN"})


def test_example_file_lists_the_quiz_settings_with_their_defaults():
    example = load_env_file(BOT_ROOT / ".env.example")
    assert example["WEAK_TOPIC_BELOW"] == "60"
    assert example["EXAM_POINTS"] == "30"
    # The example holds placeholders only, never a real token.
    assert example["MOODLE_TOKEN"] == "paste-the-token-here"
