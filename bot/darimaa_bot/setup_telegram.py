"""Connects the Telegram bot to this server: sets the webhook and the command menu.

Run once, after TELEGRAM_BOT_TOKEN and TELEGRAM_SECRET are in .env:

    python -m darimaa_bot.setup_telegram https://YOUR-DOMAIN/darimaa-bot/telegram/webhook
"""

from __future__ import annotations

import asyncio
import sys

import httpx

from . import texts
from .config import Settings
from .telegram import TelegramClient


async def connect(settings: Settings, url: str, http: httpx.AsyncClient) -> str:
    """Points Telegram at the webhook and sets the commands. Returns the bot's username."""
    if not url.startswith("https://"):
        raise ValueError("The webhook address must start with https://")
    client = TelegramClient(settings.telegram_api_url, settings.telegram_token, http)
    me = await client.get_me()
    await client.set_webhook(url, settings.telegram_secret)
    await client.set_commands(texts.TELEGRAM_COMMANDS)
    return str(me.get("username", ""))


async def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print(__doc__)
        return 2
    settings = Settings.from_env()
    if not settings.telegram_enabled:
        print("TELEGRAM_BOT_TOKEN and TELEGRAM_SECRET are not set in .env.")
        return 1
    async with httpx.AsyncClient() as http:
        username = await connect(settings, argv[1], http)
    print(f"Done: Telegram sends messages to {argv[1]}")
    print(f"In Moodle, type {username} in Site administration > Plugins > Local plugins >")
    print("Teacher & Student Assistant > Telegram bot.")
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(main(sys.argv)))
