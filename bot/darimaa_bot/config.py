"""Settings, read from environment variables or a .env file."""

from __future__ import annotations

import os
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
    """Everything the bot needs to talk to Moodle and Messenger."""

    moodle_url: str
    moodle_token: str
    page_access_token: str
    app_secret: str
    verify_token: str
    graph_url: str = "https://graph.facebook.com"
    graph_version: str = "v23.0"
    default_timezone: str = "Asia/Ulaanbaatar"
    channel: str = "messenger"

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

        return cls(
            moodle_url=need("MOODLE_URL").rstrip("/"),
            moodle_token=need("MOODLE_TOKEN"),
            page_access_token=need("FB_PAGE_ACCESS_TOKEN"),
            app_secret=need("FB_APP_SECRET"),
            verify_token=need("FB_VERIFY_TOKEN"),
            graph_url=merged.get("FB_GRAPH_URL", cls.graph_url).rstrip("/") or cls.graph_url,
            graph_version=merged.get("FB_GRAPH_VERSION", cls.graph_version) or cls.graph_version,
            default_timezone=merged.get("BOT_TIMEZONE", cls.default_timezone) or cls.default_timezone,
        )
