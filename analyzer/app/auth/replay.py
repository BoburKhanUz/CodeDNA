"""Bounded replay protection for signed requests.

A signed request is valid for ±max_skew seconds, so a captured request could
be resent inside that window. Each accepted X-Request-ID is remembered until
its timestamp leaves the window; a second request with the same ID is
rejected (REPLAY_DETECTED). Legitimate retries use a new request ID per
attempt (contract section 3).

Memory is bounded: expired entries are evicted first; if the cache is still
full, new requests are refused with ANALYZER_BUSY rather than forgetting
IDs that could still be replayed. The state is per process: with several
analyzer instances a replay could reach a different instance, which is
documented (docs/architecture/analyzer.md#replay-protection).
"""

import threading
import time
from collections import OrderedDict

from app.errors import AnalyzerError, ErrorCode


class ReplayCache:
    def __init__(self, max_entries: int, window_seconds: int) -> None:
        self._max_entries = max_entries
        self._window = window_seconds
        self._entries: OrderedDict[str, float] = OrderedDict()
        self._lock = threading.Lock()

    def remember(self, request_id: str, signed_at: int, now: float | None = None) -> None:
        current = time.time() if now is None else now
        # The request can be replayed until its timestamp is outside the window.
        expires_at = signed_at + self._window
        with self._lock:
            self._evict_expired(current)
            if request_id in self._entries:
                raise AnalyzerError(ErrorCode.REPLAY_DETECTED)
            if len(self._entries) >= self._max_entries:
                raise AnalyzerError(ErrorCode.ANALYZER_BUSY, headers={"Retry-After": "5"})
            self._entries[request_id] = expires_at

    def __len__(self) -> int:
        return len(self._entries)

    def _evict_expired(self, now: float) -> None:
        expired = [key for key, expires_at in self._entries.items() if expires_at < now]
        for key in expired:
            del self._entries[key]
