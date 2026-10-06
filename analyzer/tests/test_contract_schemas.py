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


def test_static_analysis_results_and_errors_match_their_schemas(settings: Settings) -> None:
    raw = encode(request_body(ARCHIVE))
    with TestClient(create_app(settings, resolver=resolver("172.18.0.5"), fetcher=FakeFetcher(ARCHIVE))) as client:
        ok = client.post(PATH, content=raw, headers=signed_headers(raw))
        error = client.post(PATH, content=raw, headers=signed_headers(raw, secret="wrong-secret-of-sufficient-length-00000000"))

    assert ok.status_code == 200
    assert validate(ok.json(), load("static-analysis-result.schema.json")) == []
    assert validate(error.json(), load("error.schema.json")) == []


def test_the_error_schema_lists_exactly_the_implemented_codes() -> None:
    schema = load("error.schema.json")
    assert set(schema["properties"]["error"]["properties"]["code"]["enum"]) == {code.name for code in ErrorCode}


def test_the_ir_schema_has_no_place_for_source_text() -> None:
    schema = load("static-analysis-result.schema.json")
    record = schema["$defs"]["file"]
    assert record["additionalProperties"] is False
    assert set(record["properties"]) == {"path", "extension", "language", "size_bytes", "lines", "skip_reason", "parse", "structure"}
    structure = record["properties"]["structure"]
    assert structure["additionalProperties"] is False
    assert set(structure["properties"]) == {"lines", "imports", "types", "functions"}
    for name in ("type", "function", "finding"):
        assert schema["$defs"][name]["additionalProperties"] is False
    # Names and targets cannot hold code: no whitespace runs, quotes, semicolons, parentheses or braces.
    assert '"' in schema["$defs"]["name"]["pattern"] and ";" in schema["$defs"]["name"]["pattern"]


def test_ir_1_1_keeps_every_ir_1_0_field_unchanged() -> None:
    old = load("foundation-result.schema.json")["$defs"]["file"]["properties"]
    new = load("static-analysis-result.schema.json")["$defs"]["file"]["properties"]
    assert {name: new[name] for name in old} == old
