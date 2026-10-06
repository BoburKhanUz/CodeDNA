import hashlib
import json
import logging
import os
import time
import uuid

import pytest
from fastapi.testclient import TestClient

from app.auth.hmac_signing import response_canonical_string, sign
from app.config import Settings
from app.main import create_app
from app.versions import ANALYZER_VERSION, IR_VERSION
from app.workspace.workspace import active_runs
from tests.support import (
    PATH,
    RUN_ID,
    SECRET,
    FakeFetcher,
    encode,
    make_settings,
    presigned_url,
    request_body,
    resolver,
    signed_headers,
    zip_bytes,
)

ARCHIVE = zip_bytes(
    {
        "src/Invoice.php": "<?php\nfinal class Invoice {}\n",
        "src/app.py": "SECRET_VALUE = 'never-in-the-output'\nprint(1)\n",
        "web/index.ts": "export const x = 1;\n",
        "README.md": "# Billing\n",
        "node_modules/left-pad/index.js": "module.exports = 1;\n",
    },
    directories=["src/"],
)
PRIVATE = resolver("172.18.0.5")


def client_for(settings: Settings, *archives: bytes, fetcher=None) -> TestClient:  # type: ignore[no-untyped-def]
    return TestClient(create_app(settings, resolver=PRIVATE, fetcher=fetcher or FakeFetcher(*(archives or (ARCHIVE,)))))


def post(client: TestClient, body: dict, **header_overrides) -> object:  # type: ignore[no-untyped-def, type-arg]
    raw = encode(body)
    headers = signed_headers(raw, run_id=body.get("analysis_run_id", RUN_ID))
    headers.update(header_overrides)
    return client.post(PATH, content=raw, headers=headers)


def assert_signed(response, request_id: str) -> None:  # type: ignore[no-untyped-def]
    timestamp = response.headers["x-codedna-timestamp"]
    expected = sign(SECRET, response_canonical_string(timestamp, response.status_code, PATH, request_id, response.content))
    assert response.headers["x-codedna-signature"] == expected


def test_a_signed_request_returns_a_versioned_foundation_result(settings: Settings) -> None:
    with client_for(settings) as client:
        body = request_body(ARCHIVE)
        response = post(client, body)

    assert response.status_code == 200
    data = response.json()
    assert_signed(response, data["request_id"])
    assert response.headers["x-request-id"] == data["request_id"]
    assert set(data) == {
        "contract_version",
        "result_type",
        "analysis_run_id",
        "request_id",
        "versions",
        "analysis",
        "source",
        "languages",
        "ir",
        "result_hash",
        "diagnostics",
    }
    assert data["contract_version"] == "1.0" and data["result_type"] == "foundation"
    assert data["versions"] == {"analyzer": ANALYZER_VERSION, "ir": IR_VERSION, "metrics": None, "scoring": None, "parsers": {}}
    assert data["analysis_run_id"] == RUN_ID
    assert data["source"] == {
        "sha256": body["source"]["sha256"],
        "size_bytes": len(ARCHIVE),
        "files_total": 5,
        "files_analyzed": 3,
        "files_skipped": {"ignored_path": 1, "unsupported_language": 1, "too_large": 0, "binary": 0},
        "bytes_total": sum(
            len(c)
            for c in [
                "<?php\nfinal class Invoice {}\n",
                "SECRET_VALUE = 'never-in-the-output'\nprint(1)\n",
                "export const x = 1;\n",
                "# Billing\n",
                "module.exports = 1;\n",
            ]
        ),
    }
    assert [entry["language"] for entry in data["languages"]] == ["php", "python", "typescript"]
    assert data["ir"]["version"] == IR_VERSION
    assert [f["path"] for f in data["ir"]["files"]] == ["README.md", "src/Invoice.php", "src/app.py", "web/index.ts"]
    # No scores, metrics or findings in a foundation result, and never source text.
    for absent in ("metrics", "features", "dna", "findings"):
        assert absent not in data
    assert "never-in-the-output" not in response.text and "final class" not in response.text
    assert body["source"]["url"].split("?")[1] not in response.text
    assert active_runs(settings.workspace_root) == []


def test_the_result_hash_is_deterministic_across_processes(workspace_root: str) -> None:
    hashes = []
    for _ in range(2):
        with client_for(make_settings(workspace_root)) as client:
            response = post(client, request_body(ARCHIVE))
            hashes.append((response.json()["result_hash"], response.json()["request_id"]))
    assert hashes[0][0] == hashes[1][0]
    assert hashes[0][1] != hashes[1][1]


def test_the_result_hash_covers_everything_except_request_id_and_diagnostics(settings: Settings) -> None:
    from app.services.analysis import result_hash

    with client_for(settings) as client:
        data = post(client, request_body(ARCHIVE)).json()
    assert result_hash(data) == data["result_hash"]
    data["ir"]["files"][0]["size_bytes"] += 1
    assert result_hash(data) != data["result_hash"]


def test_configuration_changes_the_result_hash(workspace_root: str) -> None:
    with client_for(make_settings(workspace_root)) as client:
        default = post(client, request_body(ARCHIVE)).json()
    with client_for(make_settings(workspace_root, max_file_bytes=30)) as client:
        stricter = post(client, request_body(ARCHIVE)).json()
    assert stricter["analysis"]["max_file_bytes"] == 30
    assert stricter["source"]["files_skipped"]["too_large"] == 1
    assert stricter["result_hash"] != default["result_hash"]


def test_a_retry_of_a_completed_run_is_answered_without_reanalysis(settings: Settings) -> None:
    fetcher = FakeFetcher(ARCHIVE)
    with client_for(settings, fetcher=fetcher) as client:
        first = post(client, request_body(ARCHIVE))
        retry = post(client, request_body(ARCHIVE, attempt=2, url=presigned_url()))

    assert retry.status_code == 200 and retry.headers["idempotent-replayed"] == "true"
    assert retry.json()["result_hash"] == first.json()["result_hash"]
    assert retry.json()["request_id"] != first.json()["request_id"]
    assert fetcher.calls == 1


def test_a_conflicting_request_for_the_same_run_is_rejected(settings: Settings) -> None:
    other = zip_bytes({"other.py": "x = 1\n"})
    with client_for(settings, ARCHIVE, other) as client:
        assert post(client, request_body(ARCHIVE)).status_code == 200
        conflict = post(client, request_body(other))
        options = post(client, request_body(ARCHIVE, options={"languages": ["php"]}))

    assert conflict.status_code == 409 and conflict.json()["error"]["code"] == "RUN_CONFLICT"
    assert options.json()["error"]["code"] == "RUN_CONFLICT"


def test_a_run_already_in_progress_is_not_started_twice(settings: Settings) -> None:
    with client_for(settings) as client:
        registry = client.app.state.registry  # type: ignore[attr-defined]
        from app.contracts.request import parse_request
        from app.services.analysis import request_fingerprint

        body = request_body(ARCHIVE)
        with registry.claim(RUN_ID, request_fingerprint(parse_request(encode(body)), settings)):
            response = post(client, body)
    assert response.status_code == 409
    assert response.json()["error"] == {
        "code": "RUN_IN_PROGRESS",
        "message": "This analysis run is already being processed.",
        "retryable": True,
        "request_id": response.json()["error"]["request_id"],
    }


def test_the_concurrency_limit_answers_busy(workspace_root: str) -> None:
    settings = make_settings(workspace_root, max_concurrency=1)
    with client_for(settings) as client, client.app.state.registry.claim("01k6p0a1b2c3d4e5f6g7h8j9zz", "other"):  # type: ignore[attr-defined]
        response = post(client, request_body(ARCHIVE))
    assert response.status_code == 503
    assert response.json()["error"]["code"] == "ANALYZER_BUSY"
    assert response.headers["retry-after"] == "5"


@pytest.mark.parametrize(
    ("headers", "code"),
    [
        ({"X-CodeDNA-Signature": "v1=" + "0" * 64}, "INVALID_SIGNATURE"),
        ({"X-CodeDNA-Signature": ""}, "INVALID_SIGNATURE"),
        ({"X-CodeDNA-Timestamp": str(int(time.time()) - 600)}, "STALE_TIMESTAMP"),
        ({"X-CodeDNA-Timestamp": str(int(time.time()) + 600)}, "STALE_TIMESTAMP"),
        ({"X-Request-ID": "not-a-uuid"}, "INVALID_SIGNATURE"),
    ],
)
def test_unauthenticated_requests_are_rejected_without_any_work(settings: Settings, headers: dict, code: str) -> None:  # type: ignore[type-arg]
    fetcher = FakeFetcher(ARCHIVE)
    with client_for(settings, fetcher=fetcher) as client:
        response = post(client, request_body(ARCHIVE), **headers)
    assert response.status_code == 401
    assert response.json()["error"]["code"] == code
    assert "x-codedna-signature" not in response.headers
    assert fetcher.calls == 0


def test_a_missing_signature_is_rejected(settings: Settings) -> None:
    with client_for(settings) as client:
        response = client.post(PATH, content=encode(request_body(ARCHIVE)), headers={"Content-Type": "application/json"})
    assert response.status_code == 401 and response.json()["error"]["code"] == "INVALID_SIGNATURE"


def test_a_replayed_request_is_rejected(settings: Settings) -> None:
    with client_for(settings) as client:
        raw = encode(request_body(ARCHIVE))
        headers = signed_headers(raw)
        assert client.post(PATH, content=raw, headers=headers).status_code == 200
        replay = client.post(PATH, content=raw, headers=headers)
    assert replay.status_code == 401 and replay.json()["error"]["code"] == "REPLAY_DETECTED"
    assert_signed(replay, headers["X-Request-ID"])


@pytest.mark.parametrize(
    ("mutate", "status", "code"),
    [
        (lambda b: b.update(contract_version="2.0"), 400, "UNSUPPORTED_CONTRACT_VERSION"),
        (lambda b: b.update(contract_version="one"), 422, "INVALID_REQUEST"),
        (lambda b: b.update(analysis_run_id="../../etc"), 422, "INVALID_REQUEST"),
        (lambda b: b.update(attempt="1"), 422, "INVALID_REQUEST"),
        (lambda b: b["source"].update(type="git"), 422, "INVALID_REQUEST"),
        (lambda b: b["source"].update(format="tar"), 422, "INVALID_REQUEST"),
        (lambda b: b["source"].update(sha256="xyz"), 422, "INVALID_REQUEST"),
        (lambda b: b["source"].update(url="file:///etc/passwd"), 422, "SOURCE_HOST_NOT_ALLOWED"),
        (lambda b: b["source"].update(url=presigned_url(host="169.254.169.254")), 422, "SOURCE_HOST_NOT_ALLOWED"),
        (lambda b: b["source"].update(url=presigned_url(host="localhost:8000")), 422, "SOURCE_HOST_NOT_ALLOWED"),
        (lambda b: b.update(options={"languages": ["cobol"]}), 422, "INVALID_REQUEST"),
        (lambda b: b.update(source="/etc/passwd"), 422, "INVALID_REQUEST"),
    ],
)
def test_invalid_bodies_are_rejected_with_signed_errors(settings: Settings, mutate, status: int, code: str) -> None:  # type: ignore[no-untyped-def]
    body = request_body(ARCHIVE)
    mutate(body)
    fetcher = FakeFetcher(ARCHIVE)
    with client_for(settings, fetcher=fetcher) as client:
        response = post(
            client, body, **({"Idempotency-Key": body["analysis_run_id"]} if isinstance(body.get("analysis_run_id"), str) else {})
        )
    assert (response.status_code, response.json()["error"]["code"]) == (status, code)
    assert_signed(response, response.json()["error"]["request_id"])
    assert fetcher.calls == 0
    # Submitted values are never echoed.
    assert "etc/passwd" not in response.text and "cobol" not in response.text


def test_unknown_fields_are_ignored_but_duplicate_keys_are_not(settings: Settings) -> None:
    with client_for(settings) as client:
        assert post(client, request_body(ARCHIVE, future_field={"x": 1})).status_code == 200
        raw = encode(request_body(ARCHIVE)).replace(b'"attempt": 1', b'"attempt": 1, "attempt": 2')
        response = client.post(PATH, content=raw, headers=signed_headers(raw, request_id=str(uuid.uuid4())))
    assert response.json()["error"] == {**response.json()["error"], "code": "INVALID_REQUEST", "details": {"reason": "malformed_json"}}


def test_the_idempotency_key_must_name_the_run(settings: Settings) -> None:
    with client_for(settings) as client:
        raw = encode(request_body(ARCHIVE))
        response = client.post(PATH, content=raw, headers=signed_headers(raw, run_id="01k6p0a1b2c3d4e5f6g7h8j9zz"))
    assert response.json()["error"]["code"] == "INVALID_REQUEST"


def test_oversized_request_bodies_are_rejected(settings: Settings) -> None:
    with client_for(settings) as client:
        body = request_body(ARCHIVE, padding="x" * 70_000)
        response = post(client, body)
    assert response.status_code == 422 and response.json()["error"]["details"] == {"reason": "body_too_large"}


@pytest.mark.parametrize(
    ("archive", "status", "code"),
    [
        (b"PK\x03\x04 not really", 422, "INVALID_ARCHIVE"),
        (zip_bytes({"../escape.py": "x"}), 422, "INVALID_ARCHIVE"),
        (zip_bytes({"README.md": "# only docs"}), 422, "NO_SUPPORTED_FILES"),
    ],
)
def test_archive_failures_are_reported_and_cleaned_up(settings: Settings, archive: bytes, status: int, code: str) -> None:
    with client_for(settings, archive) as client:
        response = post(client, request_body(archive))
    assert (response.status_code, response.json()["error"]["code"]) == (status, code)
    assert active_runs(settings.workspace_root) == []


def test_too_many_files_and_too_large_sources_use_413(workspace_root: str) -> None:
    archive = zip_bytes({f"f{i}.py": "x" for i in range(4)})
    with client_for(make_settings(workspace_root, max_files=3), archive) as client:
        assert post(client, request_body(archive)).json()["error"]["code"] == "TOO_MANY_FILES"
    with client_for(make_settings(workspace_root, max_archive_bytes=10), archive) as client:
        response = post(client, request_body(archive, run_id="01k6p0a1b2c3d4e5f6g7h8j9zz"))
    assert response.status_code == 413


def test_the_hard_time_limit_returns_a_timeout_and_cleans_up(workspace_root: str) -> None:
    def slow(target, destination, request, settings, deadline):  # type: ignore[no-untyped-def]
        with open(destination, "wb") as handle:
            handle.write(ARCHIVE)
        time.sleep(1.2)

    settings = make_settings(workspace_root, hard_timeout_seconds=1)
    with client_for(settings, fetcher=slow) as client:
        response = post(client, request_body(ARCHIVE))
    assert (response.status_code, response.json()["error"]["code"]) == (504, "ANALYSIS_TIMEOUT")
    assert response.json()["error"]["retryable"] is False
    assert active_runs(workspace_root) == []


def test_unexpected_errors_are_safe_signed_and_cleaned_up(settings: Settings) -> None:
    def broken(target, destination, request, settings, deadline):  # type: ignore[no-untyped-def]
        with open(destination, "wb") as handle:
            handle.write(b"partial")
        raise RuntimeError(f"boom at {destination} with {request.source.url}")

    with client_for(settings, fetcher=broken) as client:
        response = post(client, request_body(ARCHIVE))
    assert response.status_code == 500
    assert response.json()["error"]["code"] == "INTERNAL_ERROR"
    assert "boom" not in response.text and "tmp" not in response.text and "X-Amz" not in response.text
    assert_signed(response, response.json()["error"]["request_id"])
    assert active_runs(settings.workspace_root) == []


def test_logs_contain_only_safe_fields(settings: Settings) -> None:
    records: list[str] = []

    class Capture(logging.Handler):
        def emit(self, record: logging.LogRecord) -> None:
            records.append(json.dumps(record.__dict__, default=str))

    with client_for(settings) as client:
        capture = Capture()
        logging.getLogger("codedna").addHandler(capture)
        try:
            body = request_body(ARCHIVE)
            post(client, body)
            post(client, request_body(b"not a zip", run_id="01k6p0a1b2c3d4e5f6g7h8j9zz"))
        finally:
            logging.getLogger("codedna").removeHandler(capture)

    joined = "\n".join(records)
    assert "Analysis completed." in joined
    for secret in ("X-Amz-Signature", "minio", SECRET, "never-in-the-output", "src/app.py", settings.workspace_root):
        assert secret not in joined


def test_concurrent_runs_use_separate_workspaces(settings: Settings) -> None:
    seen: list[str] = []

    def record(target, destination, request, settings, deadline):  # type: ignore[no-untyped-def]
        seen.append(os.path.dirname(destination))
        with open(destination, "wb") as handle:
            handle.write(ARCHIVE)

    with client_for(settings, fetcher=record) as client:
        post(client, request_body(ARCHIVE))
        post(client, request_body(ARCHIVE, run_id="01k6p0a1b2c3d4e5f6g7h8j9zz"))
    assert len(set(seen)) == 2
    assert all(hashlib.sha256(path.encode()).hexdigest() for path in seen)
