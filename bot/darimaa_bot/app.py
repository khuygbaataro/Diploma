"""The web server Meta calls (the webhook), plus a health check.

Run with: uvicorn --factory darimaa_bot.app:create_app --host 127.0.0.1 --port 8100
"""

from __future__ import annotations

import hmac
import json
import logging
from contextlib import asynccontextmanager
from typing import Any, AsyncIterator

import httpx
from fastapi import BackgroundTasks, FastAPI, HTTPException, Request
from fastapi.responses import PlainTextResponse

from .config import Settings
from .conversation import Bot
from .messenger import MessengerClient, parse_events, verify_signature
from .moodle import MoodleClient

log = logging.getLogger("darimaa_bot")


def create_app(settings: Settings | None = None, transport: httpx.AsyncBaseTransport | None = None) -> FastAPI:
    """Builds the app. Tests pass their own settings and a fake HTTP transport."""
    settings = settings or Settings.from_env()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    state: dict[str, Any] = {}

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        async with httpx.AsyncClient(transport=transport) as http:
            moodle = MoodleClient(settings.moodle_url, settings.moodle_token, settings.channel, http)
            messenger = MessengerClient(settings.graph_url, settings.graph_version, settings.page_access_token, http)
            state["bot"] = Bot(moodle, messenger, settings.default_timezone)
            log.info("Teacher & Student Assistant bot started for %s", settings.moodle_url)
            yield
        state.clear()

    app = FastAPI(title="Teacher & Student Assistant bot", lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)

    @app.get("/webhook")
    async def verify_webhook(request: Request) -> PlainTextResponse:
        """Meta calls this once, when the webhook is set up, to check the verify token."""
        query = request.query_params
        token = query.get("hub.verify_token", "")
        if query.get("hub.mode") == "subscribe" and hmac.compare_digest(token, settings.verify_token):
            return PlainTextResponse(query.get("hub.challenge", ""))
        raise HTTPException(status_code=403)

    @app.post("/webhook")
    async def receive(request: Request, background: BackgroundTasks) -> dict[str, bool]:
        """Meta sends every message here. Answer at once and reply in the background."""
        body = await request.body()
        if not verify_signature(settings.app_secret, body, request.headers.get("x-hub-signature-256")):
            log.warning("Refused a webhook call with a wrong signature")
            raise HTTPException(status_code=403)
        try:
            data = json.loads(body or b"{}")
        except ValueError:
            raise HTTPException(status_code=400)
        for event in parse_events(data):
            background.add_task(state["bot"].handle, event)
        return {"ok": True}

    @app.get("/healthz")
    async def health() -> dict[str, bool]:
        return {"ok": True}

    return app
