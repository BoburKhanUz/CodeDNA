"""Contract tests for the two result types of contract 1.0.

FOUNDATION (Phase 08) stays the default and keeps its schema, fields and
semantics. STATIC ANALYSIS (Phase 09) is requested with
options.result_type and only adds to the foundation fields.
"""

from typing import Any

import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.main import create_app
from app.services import analysis as analysis_module
from app.services.analysis import result_hash
from tests.schema_check import load, validate
from tests.support import PATH, FakeFetcher, encode, make_settings, request_body, resolver, signed_headers, zip_bytes

ARCHIVE = zip_bytes(
    {
        "src/Invoice.php": "<?php\nfinal class Invoice extends Model\n{\n    public function total(array $l): int { return 1; }\n}\n",
        "src/app.py": "def run(a):\n    if a:\n        return 1\n    return 0\n",
        "src/broken.py": "def (:\n",
        "README.md": "# Billing\n",
        "node_modules/x/index.js": "module.exports = 1;\n",
    }
)
STATIC = {"result_type": "static_analysis"}
FOUNDATION_KEYS = {
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
IR_1_0_KEYS = ("path", "extension", "language", "size_bytes", "lines", "skip_reason")


def post(settings: Settings, body: dict[str, Any]) -> Any:
    raw = encode(body)
    with TestClient(create_app(settings, resolver=resolver("172.18.0.5"), fetcher=FakeFetcher(ARCHIVE))) as client:
        return client.post(PATH, content=raw, headers=signed_headers(raw))


def test_a_request_without_result_type_returns_the_phase_08_foundation_result(settings: Settings) -> None:
    response = post(settings, request_body(ARCHIVE))
    assert response.status_code == 200
    data = response.json()
    assert validate(data, load("foundation-result.schema.json")) == []
    assert set(data) == FOUNDATION_KEYS
    assert data["result_type"] == "foundation"
    assert data["versions"] == {"analyzer": data["versions"]["analyzer"], "ir": "1.0", "metrics": None, "scoring": None, "parsers": {}}
    assert set(data["analysis"]) == {"languages", "ignored_directories", "max_file_bytes"}
    assert all(set(record) == set(IR_1_0_KEYS) for record in data["ir"]["files"])


def test_an_explicit_foundation_request_is_identical_to_the_default(workspace_root: str) -> None:
    default = post(make_settings(workspace_root), request_body(ARCHIVE)).json()
    explicit = post(make_settings(workspace_root), request_body(ARCHIVE, options={"result_type": "foundation"})).json()
    assert explicit["result_hash"] == default["result_hash"]
    assert validate(explicit, load("foundation-result.schema.json")) == []


def test_a_foundation_run_never_parses(settings: Settings, monkeypatch: pytest.MonkeyPatch) -> None:
    def no_parsing(*args: Any, **kwargs: Any) -> Any:
        raise AssertionError("a foundation result must not parse")

    monkeypatch.setattr(analysis_module, "parse_files", no_parsing)
    assert post(settings, request_body(ARCHIVE)).json()["result_type"] == "foundation"


def test_a_static_analysis_request_returns_a_valid_static_analysis_result(settings: Settings) -> None:
    response = post(settings, request_body(ARCHIVE, options=STATIC))
    assert response.status_code == 200
    data = response.json()
    assert validate(data, load("static-analysis-result.schema.json")) == []
    assert data["result_type"] == "static_analysis"
    assert data["versions"]["ir"] == "1.1" and data["versions"]["metrics"] == "1.0" and data["versions"]["scoring"] is None
    assert data["parsing"]["files"]["PARSED"] == 2 and data["parsing"]["files"]["PARSE_ERROR"] == 1
    for absent in ("features", "dna", "score", "scores"):
        assert absent not in data


def test_static_analysis_keeps_every_foundation_field_unchanged(workspace_root: str) -> None:
    # Separate analyzer instances: one run ID may not be reused for a different result type.
    foundation = post(make_settings(workspace_root), request_body(ARCHIVE)).json()
    static = post(make_settings(workspace_root), request_body(ARCHIVE, options=STATIC)).json()

    assert set(static) >= FOUNDATION_KEYS
    for key in ("contract_version", "analysis_run_id", "source", "languages"):
        assert static[key] == foundation[key], key
    # versions and analysis are extended, never changed: the foundation entries keep their values,
    # except the version numbers that name what this result contains (IR 1.1, metrics 1.0).
    assert {key: static["versions"][key] for key in ("analyzer", "scoring")} == {
        key: foundation["versions"][key] for key in ("analyzer", "scoring")
    }
    assert set(foundation["versions"]) <= set(static["versions"])
    assert {key: static["analysis"][key] for key in foundation["analysis"]} == foundation["analysis"]
    # Every IR 1.0 file record is present, in the same order, with identical IR 1.0 fields.
    assert [{key: record[key] for key in IR_1_0_KEYS} for record in static["ir"]["files"]] == foundation["ir"]["files"]


def test_the_static_analysis_schema_does_not_redefine_foundation_fields() -> None:
    foundation = load("foundation-result.schema.json")
    static = load("static-analysis-result.schema.json")
    assert set(foundation["required"]) <= set(static["required"])
    for key in ("contract_version", "analysis_run_id", "request_id", "source", "result_hash", "diagnostics"):
        assert static["properties"][key] == foundation["properties"][key], key
    for key, definition in foundation["properties"]["analysis"]["properties"].items():
        assert static["properties"]["analysis"]["properties"][key]["type"] == definition["type"], key
    old_file = foundation["$defs"]["file"]["properties"]
    new_file = static["$defs"]["file"]["properties"]
    assert {key: new_file[key] for key in old_file} == old_file
    assert foundation["properties"]["result_type"] == {"const": "foundation"}
    assert static["properties"]["result_type"] == {"const": "static_analysis"}


@pytest.mark.parametrize("options", [None, STATIC], ids=["foundation", "static_analysis"])
def test_both_result_types_have_a_deterministic_result_hash(workspace_root: str, options: dict[str, str] | None) -> None:
    body = request_body(ARCHIVE) if options is None else request_body(ARCHIVE, options=options)
    first = post(make_settings(workspace_root), body).json()
    second = post(make_settings(workspace_root), body).json()
    assert first["result_hash"] == second["result_hash"]
    assert first["request_id"] != second["request_id"]
    assert result_hash(first) == first["result_hash"]
    tampered = dict(first, source={**first["source"], "files_total": first["source"]["files_total"] + 1})
    assert result_hash(tampered) != first["result_hash"]


def test_the_two_result_types_have_different_hashes(workspace_root: str) -> None:
    foundation = post(make_settings(workspace_root), request_body(ARCHIVE)).json()
    static = post(make_settings(workspace_root), request_body(ARCHIVE, options=STATIC)).json()
    assert foundation["result_hash"] != static["result_hash"]


def test_one_run_id_cannot_switch_result_type(settings: Settings) -> None:
    with TestClient(create_app(settings, resolver=resolver("172.18.0.5"), fetcher=FakeFetcher(ARCHIVE, ARCHIVE))) as client:
        for body, expected in ((request_body(ARCHIVE), 200), (request_body(ARCHIVE, options=STATIC), 409)):
            raw = encode(body)
            response = client.post(PATH, content=raw, headers=signed_headers(raw))
            assert response.status_code == expected
    assert response.json()["error"]["code"] == "RUN_CONFLICT"


def test_the_request_schema_and_parser_accept_only_known_result_types(settings: Settings) -> None:
    schema = load("analyze-request.schema.json")
    for value in ("foundation", "static_analysis"):
        assert validate(request_body(ARCHIVE, options={"result_type": value}), schema) == []
    assert validate(request_body(ARCHIVE, options={"result_type": "full"}), schema) != []
    response = post(settings, request_body(ARCHIVE, options={"result_type": "full"}))
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_REQUEST"
    assert response.json()["error"]["details"]["fields"] == ["options.result_type"]


def test_planted_payloads_are_never_executed_by_static_analysis(settings: Settings, tmp_path: Any) -> None:
    marker = tmp_path / "executed"
    archive = zip_bytes(
        {
            "index.php": f"<?php file_put_contents('{marker}', 'php');",
            "setup.py": f"open({str(marker)!r}, 'w').write('python')",
            "index.js": f"require('fs').writeFileSync({str(marker)!r}, 'node')",
            "main.go": f'package main\nimport "os"\nfunc init() {{ os.WriteFile("{marker}", nil, 0o600) }}\n',
        }
    )
    raw = encode(request_body(archive, options=STATIC))
    with TestClient(create_app(settings, resolver=resolver("172.18.0.5"), fetcher=FakeFetcher(archive))) as client:
        response = client.post(PATH, content=raw, headers=signed_headers(raw))
    assert response.status_code == 200 and response.json()["parsing"]["files"]["PARSED"] == 4
    assert not marker.exists()
