"""Small in-memory helpers: remembering recent events and limiting retries."""

from __future__ import annotations

import time
from collections import OrderedDict, deque
from typing import Callable


class TTLSet:
    """Remembers keys for a while, so the same event is not handled twice."""

    def __init__(self, ttl: float, maxsize: int = 10_000, clock: Callable[[], float] = time.monotonic) -> None:
        self.ttl = ttl
        self.maxsize = maxsize
        self.clock = clock
        self._items: OrderedDict[str, float] = OrderedDict()

    def _expire(self) -> None:
        now = self.clock()
        while self._items:
            key, added = next(iter(self._items.items()))
            if now - added < self.ttl and len(self._items) <= self.maxsize:
                break
            self._items.popitem(last=False)

    def __contains__(self, key: str) -> bool:
        self._expire()
        return key in self._items

    def add(self, key: str) -> None:
        self._expire()
        self._items[key] = self.clock()
        self._items.move_to_end(key)

    def discard(self, key: str) -> None:
        self._items.pop(key, None)

    def seen(self, key: str) -> bool:
        """Returns True if the key was already remembered; remembers it either way."""
        if not key:
            return False
        if key in self:
            return True
        self.add(key)
        return False


class RateLimiter:
    """Allows at most `limit` attempts per key within `window` seconds."""

    def __init__(self, limit: int, window: float, clock: Callable[[], float] = time.monotonic) -> None:
        self.limit = limit
        self.window = window
        self.clock = clock
        self._hits: dict[str, deque[float]] = {}

    def allow(self, key: str) -> bool:
        now = self.clock()
        hits = self._hits.setdefault(key, deque())
        while hits and now - hits[0] >= self.window:
            hits.popleft()
        if len(hits) >= self.limit:
            return False
        hits.append(now)
        return True
