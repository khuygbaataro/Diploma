"""Settings, read from environment variables or a .env file."""

from __future__ import annotations

import os
import re
from dataclasses import dataclass
from pathlib import Path


def load_env_file(path: str | os.PathLike[str]) -> dict[str, str]:
    """Reads KEY=VALUE lines from a .env file. Lines starting with # are skipped."""
    values: dict[str, str] = {}
    file = Path(path)
    if not file.is_file():
        return values
    for raw in file.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


@dataclass(frozen=True)
class Settings:
    """Everything the bot needs to talk to Moodle and the chat apps (Messenger, Telegram, or both)."""

    moodle_url: str
    moodle_token: str
    page_access_token: str = ""
    app_secret: str = ""
    verify_token: str = ""
    telegram_token: str = ""
    telegram_secret: str = ""
    telegram_api_url: str = "https://api.telegram.org"
    openai_api_key: str = ""
    openai_model: str = ""
    openai_url: str = "https://api.openai.com/v1"
    ai_per_hour: int = 20  # free questions each chat account may ask the AI assistant in an hour
    graph_url: str = "https://graph.facebook.com"
    graph_version: str = "v23.0"
    default_timezone: str = "Asia/Ulaanbaatar"
    channel: str = "messenger"
    weak_topic_below: float = 60.0  # percent: a quiz topic below this counts as weak
    exam_points: float = 30.0  # points of the final exam, for the exam estimate

    @property
    def messenger_enabled(self) -> bool:
        return bool(self.page_access_token)

    @property
    def telegram_enabled(self) -> bool:
        return bool(self.telegram_token)

    @property
    def ai_enabled(self) -> bool:
        return bool(self.openai_api_key and self.openai_model)

    @classmethod
    def from_env(cls, env: dict[str, str] | None = None) -> "Settings":
        """Builds settings from the environment, falling back to a .env file in the working folder."""
        merged = load_env_file(os.environ.get("DARIMAA_ENV_FILE", ".env"))
        merged.update(os.environ if env is None else env)

        def need(name: str) -> str:
            value = merged.get(name, "").strip()
            if not value:
                raise RuntimeError(f"{name} is not set. See .env.example.")
            return value

        def number(name: str, default: float, highest: float) -> float:
            raw = merged.get(name, "").strip()
            if not raw:
                return default
            try:
                value = float(raw)
            except ValueError:
                value = -1.0
            if not 0 < value <= highest:
                raise RuntimeError(f"{name} must be a number above 0 and at most {highest:g}. See .env.example.")
            return value

        def group(names: list[str]) -> dict[str, str]:
            """A chat app is used when any of its settings is given. Then all of them are needed."""
            values = {name: merged.get(name, "").strip() for name in names}
            if any(values.values()):
                for name in names:
                    need(name)
            return values

        messenger = group(["FB_PAGE_ACCESS_TOKEN", "FB_APP_SECRET", "FB_VERIFY_TOKEN"])
        telegram = group(["TELEGRAM_BOT_TOKEN", "TELEGRAM_SECRET"])
        ai = group(["OPENAI_API_KEY", "OPENAI_MODEL"])
        if not any(messenger.values()) and not any(telegram.values()):
            raise RuntimeError(
                "Set the Messenger settings (FB_...), the Telegram settings (TELEGRAM_...), or both. See .env.example."
            )
        if telegram["TELEGRAM_SECRET"] and not re.fullmatch(r"[A-Za-z0-9_-]{16,256}", telegram["TELEGRAM_SECRET"]):
            raise RuntimeError(
                "TELEGRAM_SECRET must be 16 to 256 letters, digits, _ or -. See .env.example."
            )

        return cls(
            moodle_url=need("MOODLE_URL").rstrip("/"),
            moodle_token=need("MOODLE_TOKEN"),
            page_access_token=messenger["FB_PAGE_ACCESS_TOKEN"],
            app_secret=messenger["FB_APP_SECRET"],
            verify_token=messenger["FB_VERIFY_TOKEN"],
            telegram_token=telegram["TELEGRAM_BOT_TOKEN"],
            telegram_secret=telegram["TELEGRAM_SECRET"],
            telegram_api_url=merged.get("TELEGRAM_API_URL", cls.telegram_api_url).rstrip("/") or cls.telegram_api_url,
            openai_api_key=ai["OPENAI_API_KEY"],
            openai_model=ai["OPENAI_MODEL"],
            openai_url=merged.get("OPENAI_URL", cls.openai_url).rstrip("/") or cls.openai_url,
            ai_per_hour=int(number("AI_QUESTIONS_PER_HOUR", cls.ai_per_hour, 1000)),
            graph_url=merged.get("FB_GRAPH_URL", cls.graph_url).rstrip("/") or cls.graph_url,
            graph_version=merged.get("FB_GRAPH_VERSION", cls.graph_version) or cls.graph_version,
            default_timezone=merged.get("BOT_TIMEZONE", cls.default_timezone) or cls.default_timezone,
            weak_topic_below=number("WEAK_TOPIC_BELOW", cls.weak_topic_below, 100),
            exam_points=number("EXAM_POINTS", cls.exam_points, 1000),
        )
