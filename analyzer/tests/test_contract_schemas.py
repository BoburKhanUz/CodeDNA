"""Requests and responses match the published JSON Schemas
(packages/api-contracts/analyzer/v1)."""

from fastapi.testclient import TestClient

from app.config import Settings
from app.errors import ErrorCode
from app.main import create_app
from tests.schema_check import load, validate
from tests.support import PATH, FakeFetcher, encode, request_body, resolver, signed_headers, zip_bytes

ARCHIVE = zip_bytes({"src/a.py": "print(1)\n", "lib/b.go": "package b\n", "README.md": "# x\n", "img.ts": b"\x00\x01"})


def test_request_bodies_match_the_request_schema() -> None:
    schema = load("analyze-request.schema.json")
    assert validate(request_body(ARCHIVE), schema) == []
    assert validate(request_body(ARCHIVE, options={"languages": ["php", "go"]}), schema) == []
    assert validate(request_body(ARCHIVE, attempt=0), schema) != []
    assert validate(request_body(ARCHIVE, options={"languages": ["cobol"]}), schema) != []


def test_foundation_results_and_errors_match_their_schemas(settings: Settings) -> None:
    raw = encode(request_body(ARCHIVE))
    with TestClient(create_app(settings, resolver=resolver("172.18.0.5"), fetcher=FakeFetcher(ARCHIVE))) as client:
        ok = client.post(PATH, content=raw, headers=signed_headers(raw))
        error = client.post(PATH, content=raw, headers=signed_headers(raw, secret="wrong-secret-of-sufficient-length-00000000"))

    assert ok.status_code == 200
    assert validate(ok.json(), load("foundation-result.schema.json")) == []
    assert validate(error.json(), load("error.schema.json")) == []


def test_the_error_schema_lists_exactly_the_implemented_codes() -> None:
    schema = load("error.schema.json")
    assert set(schema["properties"]["error"]["properties"]["code"]["enum"]) == {code.name for code in ErrorCode}


def test_the_ir_schema_has_no_place_for_source_text() -> None:
    record = load("foundation-result.schema.json")["$defs"]["file"]
    assert record["additionalProperties"] is False
    assert set(record["properties"]) == {"path", "extension", "language", "size_bytes", "lines", "skip_reason"}
