"""Run coordination for one analyzer process.

- In-flight runs: a concurrent request for a run that is being processed
  gets RUN_IN_PROGRESS (same request) or RUN_CONFLICT (different source or
  options).
- Completed results: a small cache, bounded by bytes and age, so a retry of
  a run that just succeeded (e.g. Laravel lost the response) is answered
  without analyzing again. A later request for the same run with a different
  source or options gets RUN_CONFLICT while the entry lives.
- Concurrency: at most ANALYZER_MAX_CONCURRENCY analyses at once; more get
  ANALYZER_BUSY.

This is coordination state, not analysis state: each run's data lives only
in its own workspace and local variables. All state is per process and
lost on restart, which is safe because analysis is deterministic.
"""

import threading
import time
from collections import OrderedDict
from collections.abc import Iterator
from contextlib import contextmanager
from dataclasses import dataclass
from typing import Any

from app.errors import AnalyzerError, ErrorCode


@dataclass(frozen=True)
class _Completed:
    fingerprint: str
    result: dict[str, Any]
    size: int
    expires_at: float


class RunRegistry:
    def __init__(self, max_concurrency: int, cache_bytes: int, cache_seconds: int) -> None:
        self._max_concurrency = max_concurrency
        self._cache_bytes = cache_bytes
        self._cache_seconds = cache_seconds
        self._lock = threading.Lock()
        self._in_flight: dict[str, str] = {}
        self._completed: OrderedDict[str, _Completed] = OrderedDict()
        self._cached_bytes = 0

    def cached_result(self, run_id: str, fingerprint: str) -> dict[str, Any] | None:
        with self._lock:
            self._evict(time.monotonic())
            entry = self._completed.get(run_id)
            if entry is None:
                return None
            if entry.fingerprint != fingerprint:
                raise AnalyzerError(ErrorCode.RUN_CONFLICT)
            return entry.result

    @contextmanager
    def claim(self, run_id: str, fingerprint: str) -> Iterator[None]:
        with self._lock:
            current = self._in_flight.get(run_id)
            if current is not None:
                raise AnalyzerError(ErrorCode.RUN_IN_PROGRESS if current == fingerprint else ErrorCode.RUN_CONFLICT)
            if len(self._in_flight) >= self._max_concurrency:
                raise AnalyzerError(ErrorCode.ANALYZER_BUSY, headers={"Retry-After": "5"})
            self._in_flight[run_id] = fingerprint
        try:
            yield
        finally:
            with self._lock:
                self._in_flight.pop(run_id, None)

    def store(self, run_id: str, fingerprint: str, result: dict[str, Any], size: int) -> None:
        if size > self._cache_bytes:
            return
        with self._lock:
            self._evict(time.monotonic())
            previous = self._completed.pop(run_id, None)
            if previous is not None:
                self._cached_bytes -= previous.size
            while self._completed and self._cached_bytes + size > self._cache_bytes:
                _, oldest = self._completed.popitem(last=False)
                self._cached_bytes -= oldest.size
            self._completed[run_id] = _Completed(fingerprint, result, size, time.monotonic() + self._cache_seconds)
            self._cached_bytes += size

    def in_flight(self) -> int:
        with self._lock:
            return len(self._in_flight)

    def _evict(self, now: float) -> None:
        for run_id in [key for key, entry in self._completed.items() if entry.expires_at <= now]:
            self._cached_bytes -= self._completed.pop(run_id).size
