"""Cooperative time limits.

Nothing from the source is ever executed, so there is no foreign process to
kill: every long-running step (download, extraction, discovery) checks its
deadline regularly and stops with a deterministic error.
"""

import time

from app.errors import AnalyzerError, ErrorCode


class Deadline:
    def __init__(self, seconds: float, code: ErrorCode = ErrorCode.ANALYSIS_TIMEOUT) -> None:
        self._expires_at = time.monotonic() + seconds
        self._code = code

    def remaining(self) -> float:
        return self._expires_at - time.monotonic()

    def check(self) -> None:
        if self.remaining() <= 0:
            raise AnalyzerError(self._code)

    def sooner(self, seconds: float, code: ErrorCode) -> "Deadline":
        """A child deadline that ends at the earlier of this one and now + seconds."""
        child = Deadline(min(seconds, max(self.remaining(), 0.0)), code)
        if self.remaining() <= seconds:
            child._code = self._code
        return child
