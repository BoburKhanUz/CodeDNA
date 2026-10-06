"""Static metrics 1.0 and structural findings, computed only from IR 1.1."""

from dataclasses import replace
from pathlib import Path
from typing import Any

from app.config import Settings
from app.deadline import Deadline
from app.discovery.discover import FileRecord, discover
from app.metrics.aggregate import average, compute_metrics
from app.metrics.findings import MAX_FINDINGS, RULES, compute_findings
from app.parsing.parser import LIMIT_EXCEEDED, PARSE_ERROR, PARSE_TIMEOUT, ParsedFile, parse_files
from app.parsing.walker import WalkResult


def analyze_tree(
    root: Path, settings: Settings, languages: tuple[str, ...] = ("python", "go", "javascript")
) -> tuple[list[FileRecord], dict[str, ParsedFile]]:
    found = discover(str(root), ignored_directories=(), languages=languages, max_file_bytes=settings.max_file_bytes, deadline=Deadline(30))
    return found.files, parse_files(str(root), found.files, settings, Deadline(30))


def test_averages_are_decimal_rounded_half_to_even() -> None:
    assert average(10, 3) == 3.3333
    assert average(2, 3) == 0.6667
    assert average(1, 8) == 0.125
    assert average(5, 100_000) == 0.0
    assert average(25, 200_000) == 0.0001
    assert average(15, 200_000) == 0.0001  # 0.000075 -> 0.0001 (half-even on the fifth place)
    assert average(0, 0) is None


def test_metrics_aggregate_parsed_files_per_language_and_overall(tmp_path: Path, settings: Settings) -> None:
    (tmp_path / "a.py").write_text("# c\n\ndef f(a, b):\n    if a:\n        return b\n    return a\n")
    (tmp_path / "b.py").write_text("class K(Base):\n    def m(self):\n        for x in y:\n            if x and y:\n                pass\n")
    (tmp_path / "broken.py").write_text("def (:\n")
    (tmp_path / "c.go").write_text("package c\n\ntype T struct{}\n\nfunc G() {}\n")
    records, parsed = analyze_tree(tmp_path, settings)
    metrics = compute_metrics(records, parsed)

    assert metrics["version"] == "1.0"
    python = metrics["by_language"]["python"]
    assert python["files_analyzable"] == 3
    assert python["files_parsed"] == 2 and python["files_parse_error"] == 1
    # Lines of PARSED files only (a.py: 6, b.py: 5).
    assert (python["lines_total"], python["lines_code"], python["lines_comment"], python["lines_blank"]) == (11, 9, 1, 1)
    assert python["types"] == 1 and python["types_by_kind"] == {"class": 1} and python["types_with_bases"] == 1
    assert (python["functions_total"], python["functions"], python["methods"], python["anonymous_functions"]) == (2, 1, 1, 0)
    # f: CC 2, nesting 1, 2 params, 4 lines; m: CC 4 (for, if, and), nesting 2, 0 params, 4 lines.
    assert (python["complexity_total"], python["complexity_avg"], python["complexity_max"]) == (6, 3.0, 4)
    assert (python["parameters_avg"], python["parameters_max"]) == (1.0, 2)
    assert (python["nesting_avg"], python["nesting_max"]) == (1.5, 2)
    assert (python["function_lines_avg"], python["function_lines_max"]) == (4.0, 4)
    assert python["complexity_over_threshold"] == 0 and python["complexity_threshold"] == 10
    assert python["unsupported"] == []

    go = metrics["by_language"]["go"]
    assert go["types_with_bases"] is None and go["unsupported"] == ["types_with_bases"]
    assert go["types_by_kind"] == {"struct": 1}

    overall = metrics["overall"]
    assert overall["files_analyzable"] == 4 and overall["files_parsed"] == 3
    assert overall["functions_total"] == 3 and overall["complexity_total"] == 7
    # Not every language can report inheritance, so the overall value is unknown.
    assert overall["types_with_bases"] is None and overall["unsupported"] == ["types_with_bases"]


def test_metrics_without_functions_are_null_not_zero(tmp_path: Path, settings: Settings) -> None:
    (tmp_path / "data.py").write_text("X = 1\n")
    records, parsed = analyze_tree(tmp_path, settings)
    python = compute_metrics(records, parsed)["by_language"]["python"]
    for key in (
        "function_lines_avg",
        "function_lines_max",
        "parameters_avg",
        "parameters_max",
        "nesting_avg",
        "nesting_max",
        "complexity_avg",
        "complexity_max",
    ):
        assert python[key] is None, key
    assert python["functions_total"] == 0 and python["complexity_total"] == 0


def test_unparsed_files_contribute_counts_but_no_measurements(tmp_path: Path, settings: Settings) -> None:
    (tmp_path / "a.py").write_text("def f():\n    pass\n" * 50)
    records, parsed = analyze_tree(tmp_path, replace(settings, max_ast_nodes=10, max_total_ast_nodes=10))
    python = compute_metrics(records, parsed)["by_language"]["python"]
    assert python["files_limit_exceeded"] == 1 and python["files_parsed"] == 0
    assert python["lines_total"] == 0 and python["functions_total"] == 0 and python["ast_max_depth"] is None


def _parsed_function(**overrides: Any) -> ParsedFile:
    function = {
        "kind": "function",
        "name": "f",
        "line": 3,
        "column": 0,
        "end_line": 3,
        "visibility": None,
        "parameters": 0,
        "parent_type": None,
        "complexity": 1,
        "max_nesting": 0,
    } | overrides
    walk = WalkResult(1, 1, 0, None, {"total": 1, "code": 1, "comment": 0, "blank": 0}, [], [], [function])
    return ParsedFile("python", "PARSED", ast_nodes=1, ast_max_depth=1, error_nodes=0, walk=walk)


def _record(path: str) -> FileRecord:
    return FileRecord(path, ".py", "python", 1, 1, None)


def test_findings_fire_strictly_above_their_thresholds() -> None:
    at = _parsed_function(complexity=10, max_nesting=4, end_line=102, parameters=5)
    above = _parsed_function(complexity=11, max_nesting=5, end_line=103, parameters=6)
    findings = compute_findings([_record("at.py"), _record("above.py")], {"at.py": at, "above.py": above})
    assert findings["total"] == 4
    assert {item["path"] for item in findings["items"]} == {"above.py"}
    by_rule = {item["rule_id"]: item for item in findings["items"]}
    assert by_rule["complexity/cyclomatic"] | {} == {
        "rule_id": "complexity/cyclomatic",
        "severity": "medium",
        "path": "above.py",
        "line": 3,
        "column": 0,
        "value": 11,
        "threshold": 10,
        "message": "Function has cyclomatic complexity 11 (threshold 10).",
    }
    assert by_rule["structure/nesting-depth"]["value"] == 5
    assert by_rule["structure/function-length"]["value"] == 101
    assert by_rule["structure/parameter-count"]["message"] == "Function declares 6 parameters (threshold 5)."
    assert findings["by_rule"] == {
        rule.rule_id: (0 if rule.rule_id in ("structure/class-length", "parse/syntax-error") else 1) for rule in RULES
    }


def test_findings_are_sorted_capped_and_counted() -> None:
    records = [_record(f"f{i:05d}.py") for i in range(MAX_FINDINGS + 10)]
    parsed = {record.path: _parsed_function(complexity=50) for record in reversed(records)}
    findings = compute_findings(list(reversed(records)), parsed)
    assert findings["total"] == MAX_FINDINGS + 10
    assert findings["truncated"] is True and len(findings["items"]) == MAX_FINDINGS
    assert [item["path"] for item in findings["items"]] == [record.path for record in records[:MAX_FINDINGS]]


def test_parse_errors_are_findings_but_limits_and_timeouts_are_not() -> None:
    records = [_record("e.py"), _record("t.py"), _record("l.py")]
    parsed = {
        "e.py": ParsedFile("python", PARSE_ERROR, ast_nodes=5, ast_max_depth=2, error_nodes=2, first_error=(4, 7)),
        "t.py": ParsedFile("python", PARSE_TIMEOUT),
        "l.py": ParsedFile("python", LIMIT_EXCEEDED, limit="ast_nodes"),
    }
    findings = compute_findings(records, parsed)
    assert findings["items"] == [
        {
            "rule_id": "parse/syntax-error",
            "severity": "medium",
            "path": "e.py",
            "line": 4,
            "column": 7,
            "value": 2,
            "threshold": None,
            "message": "File has 2 syntax error node(s) and was not measured.",
        }
    ]


def test_finding_messages_never_contain_names_or_source(tmp_path: Path, settings: Settings) -> None:
    body = "".join(f"    if secret_{i} and token_{i}:\n        pass\n" for i in range(12))
    (tmp_path / "a.py").write_text(f"def very_secret_function_name(a, b, c, d, e, f):\n{body}")
    records, parsed = analyze_tree(tmp_path, settings)
    findings = compute_findings(records, parsed)
    assert {item["rule_id"] for item in findings["items"]} == {"complexity/cyclomatic", "structure/parameter-count"}
    for item in findings["items"]:
        assert "secret" not in item["message"] and "very" not in item["message"] and "token" not in item["message"]


def test_large_types_are_reported() -> None:
    walk = WalkResult(
        1,
        1,
        0,
        None,
        {"total": 1, "code": 1, "comment": 0, "blank": 0},
        [],
        [{"kind": "class", "name": "K", "line": 1, "column": 0, "end_line": 501, "visibility": None, "bases": [], "methods": 0}],
        [],
    )
    findings = compute_findings(
        [_record("k.py")], {"k.py": ParsedFile("python", "PARSED", ast_nodes=1, ast_max_depth=1, error_nodes=0, walk=walk)}
    )
    assert [(item["rule_id"], item["value"], item["message"]) for item in findings["items"]] == [
        ("structure/class-length", 501, "Class spans 501 lines (threshold 500).")
    ]
