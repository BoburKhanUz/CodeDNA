"""Error vocabulary of the internal contract (section 5).

Messages are fixed, user-safe strings. They never contain exception text,
file paths, URLs, hostnames or source content. ``details`` holds only
fixed identifiers and numbers (e.g. ``{"reason": "path_traversal"}``).
"""

from enum import Enum
from typing import Any


class ErrorCode(Enum):
    # value: (HTTP status, retryable, message)
    UNSUPPORTED_CONTRACT_VERSION = (400, False, "The contract version is not supported.")
    INVALID_SIGNATURE = (401, False, "The request signature is missing or invalid.")
    STALE_TIMESTAMP = (401, False, "The request timestamp is outside the allowed window.")
    REPLAY_DETECTED = (401, False, "This request was already received.")
    NOT_FOUND = (404, False, "Not found.")
    METHOD_NOT_ALLOWED = (405, False, "Method not allowed.")
    RUN_IN_PROGRESS = (409, True, "This analysis run is already being processed.")
    RUN_CONFLICT = (409, False, "This analysis run was already requested with a different source or options.")
    SOURCE_TOO_LARGE = (413, False, "The source exceeds the maximum allowed size.")
    TOO_MANY_FILES = (413, False, "The source contains more files than allowed.")
    INVALID_REQUEST = (422, False, "The request is invalid.")
    SOURCE_HOST_NOT_ALLOWED = (422, False, "The source location is not allowed.")
    SOURCE_URL_EXPIRED = (422, True, "The source URL has expired.")
    SOURCE_CHECKSUM_MISMATCH = (422, False, "The downloaded source does not match the expected size or checksum.")
    INVALID_ARCHIVE = (422, False, "The source archive is invalid or unsafe.")
    NO_SUPPORTED_FILES = (422, False, "The source contains no files in a supported language.")
    SOURCE_FETCH_FAILED = (502, True, "The source could not be downloaded.")
    ANALYZER_BUSY = (503, True, "The analyzer is busy. Retry later.")
    ANALYSIS_TIMEOUT = (504, False, "The analysis exceeded its time limit.")
    INTERNAL_ERROR = (500, True, "An unexpected analyzer error occurred.")

    @property
    def status(self) -> int:
        return self.value[0]

    @property
    def retryable(self) -> bool:
        return self.value[1]

    @property
    def message(self) -> str:
        return self.value[2]


class AnalyzerError(Exception):
    """An expected failure with a contract error code."""

    def __init__(
        self,
        code: ErrorCode,
        details: dict[str, Any] | None = None,
        headers: dict[str, str] | None = None,
    ) -> None:
        super().__init__(code.name)
        self.code = code
        self.details = details or {}
        self.headers = headers or {}


def error_body(code: ErrorCode, request_id: str | None, details: dict[str, Any] | None = None) -> dict[str, Any]:
    error: dict[str, Any] = {
        "code": code.name,
        "message": code.message,
        "retryable": code.retryable,
        "request_id": request_id,
    }
    if details:
        error["details"] = details
    return {"error": error}
