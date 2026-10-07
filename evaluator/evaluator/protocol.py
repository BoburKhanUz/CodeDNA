"""The spool protocol between Laravel and the evaluator (codedna-evaluator/1).

A request carries source, an entry point and test *inputs* only: expected
outputs and hidden-test verdicts never reach the evaluator. Everything in a
request is validated strictly; anything unexpected is rejected, never
interpreted (there is no command, image, runtime or dependency field).
"""

from __future__ import annotations

import json
import re
from typing import Any

from evaluator import PROTOCOL, VERSION
from evaluator.config import Limits

ID = re.compile(r"^[0-9a-z]{26}$")
ENTRYPOINT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]{0,63}$")
CASE_ID = re.compile(r"^[a-z0-9_-]{1,32}$")
REQUEST_KEYS = {"protocol", "id", "language", "entrypoint", "source", "cases"}
MAX_ARGS_BYTES = 16384
MAX_VALUE_BYTES = 4096
RUNTIME = "python3.11"


class RejectedRequestError(ValueError):
    """The request is not a valid codedna-evaluator/1 request."""


def parse_request(raw: bytes, limits: Limits) -> dict[str, Any]:
    if len(raw) > limits.max_source_bytes * 4 + 65536:
        raise RejectedRequestError("request_too_large")
    try:
        request = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RejectedRequestError("not_json") from error
    if not isinstance(request, dict) or set(request) != REQUEST_KEYS:
        raise RejectedRequestError("fields")
    if request["protocol"] != PROTOCOL:
        raise RejectedRequestError("protocol")
    if not isinstance(request["id"], str) or not ID.match(request["id"]):
        raise RejectedRequestError("id")
    if request["language"] != "python":
        raise RejectedRequestError("language")
    if not isinstance(request["entrypoint"], str) or not ENTRYPOINT.match(request["entrypoint"]):
        raise RejectedRequestError("entrypoint")
    source = request["source"]
    if not isinstance(source, str) or "\x00" in source or len(source.encode("utf-8")) > limits.max_source_bytes:
        raise RejectedRequestError("source")
    cases = request["cases"]
    if not isinstance(cases, list) or not 1 <= len(cases) <= limits.max_cases:
        raise RejectedRequestError("cases")
    seen: set[str] = set()
    for case in cases:
        if not isinstance(case, dict) or set(case) != {"id", "args"}:
            raise RejectedRequestError("case")
        if not isinstance(case["id"], str) or not CASE_ID.match(case["id"]) or case["id"] in seen:
            raise RejectedRequestError("case_id")
        if not isinstance(case["args"], list):
            raise RejectedRequestError("case_args")
        seen.add(case["id"])
    if len(json.dumps([c["args"] for c in cases]).encode()) > MAX_ARGS_BYTES:
        raise RejectedRequestError("args_too_large")
    return request


def parse_records(lines: list[str]) -> list[dict[str, Any]]:
    """Runner output as records; lines that are not JSON objects are ignored (they are untrusted)."""
    records: list[dict[str, Any]] = []
    for line in lines:
        try:
            record = json.loads(line)
        except (json.JSONDecodeError, RecursionError):
            continue
        if isinstance(record, dict) and isinstance(record.get("type"), str):
            records.append(record)
    return records


def _int(value: Any) -> int:
    return value if isinstance(value, int) and not isinstance(value, bool) and 0 <= value <= 10**7 else 0


def sanitize_inspection(record: dict[str, Any] | None) -> dict[str, Any]:
    if record is None:
        return {"syntax_valid": False, "syntax_error_line": None, "functions": [], "classes": []}
    functions = [
        {
            "name": str(f.get("name", ""))[:64],
            "line": _int(f.get("line")),
            "lines": _int(f.get("lines")),
            "parameters": _int(f.get("parameters")),
            "complexity": _int(f.get("complexity")),
            "nesting": _int(f.get("nesting")),
        }
        for f in record.get("functions", [])[:200]
        if isinstance(f, dict)
    ]
    classes = [
        {"name": str(c.get("name", ""))[:64], "line": _int(c.get("line")), "lines": _int(c.get("lines")), "methods": _int(c.get("methods"))}
        for c in record.get("classes", [])[:200]
        if isinstance(c, dict)
    ]
    line = record.get("syntax_error_line")
    return {
        "syntax_valid": record.get("syntax_valid") is True,
        "syntax_error_line": _int(line) if line is not None else None,
        "functions": functions if isinstance(record.get("functions"), list) else [],
        "classes": classes if isinstance(record.get("classes"), list) else [],
    }


def result(request_id: str, status: str, **fields: Any) -> dict[str, Any]:
    return {
        "protocol": PROTOCOL,
        "id": request_id,
        "evaluator_version": VERSION,
        "runtime": RUNTIME,
        "status": status,
        "load_error": fields.get("load_error"),
        "inspection": fields.get("inspection"),
        "cases": fields.get("cases", []),
        "duration_ms": fields.get("duration_ms", 0),
    }


def case_results(case_ids: list[str], records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """One result per requested case, in request order; the first record per id wins."""
    found: dict[str, dict[str, Any]] = {}
    for record in records:
        case_id = record.get("id")
        if record.get("type") != "case" or not isinstance(case_id, str) or case_id not in case_ids or case_id in found:
            continue
        if record.get("ok") is True:
            value = record.get("value")
            if len(json.dumps(value).encode()) > MAX_VALUE_BYTES:
                found[case_id] = {"id": case_id, "status": "ERROR", "error": "ValueTooLarge"}
            else:
                found[case_id] = {"id": case_id, "status": "OK", "value": value}
        else:
            error = record.get("error")
            found[case_id] = {"id": case_id, "status": "ERROR", "error": error[:64] if isinstance(error, str) else "Error"}
    return [found.get(case_id, {"id": case_id, "status": "MISSING"}) for case_id in case_ids]
