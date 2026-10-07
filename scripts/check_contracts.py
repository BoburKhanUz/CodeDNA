#!/usr/bin/env python3
"""Cross-service contract parity checks for CodeDNA (Phase 22).

Each service's own test suite checks its side of a contract. These checks
compare the two sides directly, from source, where no single container can
see both (standard library only, deterministic output):

  1. Every API error code Laravel can answer (ErrorCode.php) is known to the
     frontend (API_ERROR_CODES in frontend/src/lib/api/types.ts), and the
     frontend knows no code the backend cannot send (except NETWORK_ERROR,
     which the browser produces itself).
  2. The evaluator result statuses the backend schema accepts are exactly the
     statuses the evaluator service writes.
  3. The shared HMAC vector exists in packages/api-contracts, where both
     Laravel's and the analyzer's tests read it.

Usage: python3 scripts/check_contracts.py
Exit code 0 on success, 1 on any failure.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FRONTEND_ONLY_CODES = {"NETWORK_ERROR"}


def backend_error_codes() -> set[str]:
    source = (ROOT / "backend/app/Http/Errors/ErrorCode.php").read_text(encoding="utf-8")
    return set(re.findall(r"^\s*case \w+ = '([A-Z0-9_]+)';", source, re.M))


def frontend_error_codes() -> set[str]:
    source = (ROOT / "frontend/src/lib/api/types.ts").read_text(encoding="utf-8")
    match = re.search(r"API_ERROR_CODES = \[(.*?)\] as const", source, re.S)
    if match is None:
        raise ValueError("API_ERROR_CODES not found in frontend/src/lib/api/types.ts")
    return set(re.findall(r'"([A-Z0-9_]+)"', match.group(1)))


def check_error_codes() -> list[str]:
    backend, frontend = backend_error_codes(), frontend_error_codes()
    errors = []
    if not backend:
        errors.append("no error codes found in backend/app/Http/Errors/ErrorCode.php")
    errors += [f"error code {code} is sent by the backend but unknown to the frontend" for code in sorted(backend - frontend)]
    errors += [f"error code {code} is known to the frontend but never sent by the backend" for code in sorted(frontend - backend - FRONTEND_ONLY_CODES)]
    return errors


def check_evaluator_statuses() -> list[str]:
    schema = json.loads((ROOT / "backend/resources/challenges/evaluator-result-1.schema.json").read_text(encoding="utf-8"))
    accepted = set(schema["properties"]["status"]["enum"])
    service = (ROOT / "evaluator/evaluator/service.py").read_text(encoding="utf-8")
    # Every result status is written in service.py, as `status = "X"` or protocol.result(id, "X", ...).
    written = set(re.findall(r'(?:\bstatus = |protocol\.result\(\w+(?:\["id"\])?, )"([A-Z_]+)"', service))
    if not written:
        return ["no result statuses found in evaluator/evaluator/service.py"]
    errors = [f"evaluator status {s} is written by the evaluator but refused by the backend schema" for s in sorted(written - accepted)]
    errors += [f"evaluator status {s} is accepted by the backend schema but never written by the evaluator" for s in sorted(accepted - written)]
    return errors


def check_hmac_vector() -> list[str]:
    path = ROOT / "packages/api-contracts/analyzer/v1/hmac-vectors.json"
    if not path.is_file():
        return [f"missing shared HMAC vector {path.relative_to(ROOT)}"]
    vector = json.loads(path.read_text(encoding="utf-8"))
    required = {"method", "path", "status", "timestamp", "request_id", "body", "request_signature", "response_signature"}
    return [f"{path.relative_to(ROOT)} lacks {field}" for field in sorted(required - vector.keys())]


def main() -> int:
    errors = check_error_codes() + check_evaluator_statuses() + check_hmac_vector()
    for error in errors:
        print(f"ERROR {error}")
    print(f"checked contract parity; {len(errors)} problem(s)")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
