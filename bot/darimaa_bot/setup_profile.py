"""Sets the Messenger welcome screen: Get Started button, greeting and menu.

Run once after the Page is connected: python -m darimaa_bot.setup_profile
"""

from __future__ import annotations

import asyncio

import httpx

from . import texts
from .config import Settings
from .messenger import MessengerClient

PROFILE = {
    "get_started": {"payload": "GET_STARTED"},
    "greeting": [{"locale": "default", "text": texts.PROFILE_GREETING}],
    "persistent_menu": [
        {
            "locale": "default",
            "composer_input_disabled": False,
            "call_to_actions": [
                {"type": "postback", "title": texts.MENU_PLAN, "payload": "PLAN"},
                {"type": "postback", "title": texts.MENU_OVERDUE, "payload": "OVERDUE"},
                {"type": "postback", "title": texts.MENU_HELP, "payload": "HELP"},
            ],
        }
    ],
}


async def main() -> None:
    settings = Settings.from_env()
    async with httpx.AsyncClient() as http:
        client = MessengerClient(settings.graph_url, settings.graph_version, settings.page_access_token, http)
        await client.set_profile(PROFILE)
    print("Done: the Get Started button, greeting and menu are set.")


if __name__ == "__main__":
    asyncio.run(main())
