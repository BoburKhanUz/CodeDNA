"""Structured JSON logs (one object per line on stdout).

Only safe operational fields are logged: request and run IDs, attempt,
status, error code, durations, counts and byte sizes. Never source
contents, archive entry names, URLs (pre-signed URLs are bearer
credentials), secrets, signatures or exception messages.
"""

import json
import logging
import sys
from typing import Any

SAFE_FIELDS = (
    "request_id",
    "analysis_run_id",
    "attempt",
    "status",
    "error_code",
    "duration_ms",
    "files_total",
    "files_analyzed",
    "bytes_total",
    "size_bytes",
    "result_hash",
    "analyzer_version",
    "exception_type",
    "removed",
)


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        entry: dict[str, Any] = {
            "level": record.levelname.lower(),
            "logger": record.name,
            "message": record.getMessage(),
        }
        for name in SAFE_FIELDS:
            value = getattr(record, name, None)
            if value is not None:
                entry[name] = value
        return json.dumps(entry, sort_keys=True)


def configure_logging(level: str) -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    logger = logging.getLogger("codedna")
    logger.handlers[:] = [handler]
    logger.setLevel(level)
    logger.propagate = False
