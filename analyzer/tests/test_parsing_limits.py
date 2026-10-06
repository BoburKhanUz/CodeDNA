"""Parser resource limits: per-file nodes and time, run-wide nodes and files, the run deadline."""

import time
from dataclasses import replace
from pathlib import Path

import pytest

from app.config import Settings
from app.deadline import Deadline
from app.discovery.discover import discover
from app.errors import AnalyzerError, ErrorCode
from app.parsing import walker as walker_module
from app.parsing.parser import LIMIT_EXCEEDED, PARSE_TIMEOUT, PARSED, ParsedFile, parse_files


def run(root: Path, settings: Settings, deadline: Deadline | None = None) -> dict[str, ParsedFile]:
    found = discover(
        str(root),
        ignored_directories=(),
        languages=("python", "javascript", "c"),
        max_file_bytes=settings.max_file_bytes,
        deadline=Deadline(30),
    )
    return parse_files(str(root), found.files, settings, deadline or Deadline(30))


def test_a_tree_larger_than_max_ast_nodes_is_limit_exceeded(tmp_path: Path, settings: Settings) -> None:
    (tmp_path / "big.py").write_text("x = 1\n" * 200)
    (tmp_path / "small.py").write_text("x = 1\n")
    results = run(tmp_path, replace(settings, max_ast_nodes=100, max_total_ast_nodes=10_000))
    assert results["big.py"].status == LIMIT_EXCEEDED
    assert results["big.py"].limit == "ast_nodes"
    assert results["big.py"].ast_nodes is not None and results["big.py"].ast_nodes > 100
    assert results["big.py"].structure_dict() is None
    assert results["small.py"].status == PARSED


def test_the_run_wide_node_budget_cuts_deterministically_in_path_order(tmp_path: Path, settings: Settings) -> None:
    for name in ("a.py", "b.py", "c.py", "d.py"):
        (tmp_path / name).write_text("x = 1\ny = 2\n")
    nodes = run(tmp_path, settings)["a.py"].ast_nodes
    assert nodes is not None
    limited = replace(settings, max_ast_nodes=nodes, max_total_ast_nodes=nodes * 2 + 1)
    first = run(tmp_path, limited)
    second = run(tmp_path, limited)
    statuses = {path: (result.status, result.limit) for path, result in first.items()}
    assert statuses == {
        "a.py": (PARSED, None),
        "b.py": (PARSED, None),
        "c.py": (LIMIT_EXCEEDED, "total_ast_nodes"),
        "d.py": (LIMIT_EXCEEDED, "total_ast_nodes"),
    }
    assert {path: r.parse_dict() for path, r in first.items()} == {path: r.parse_dict() for path, r in second.items()}


def test_the_parsed_file_limit(tmp_path: Path, settings: Settings) -> None:
    for name in ("a.py", "b.py", "c.py"):
        (tmp_path / name).write_text("x = 1\n")
    results = run(tmp_path, replace(settings, max_parsed_files=2))
    assert [(r.status, r.limit) for _, r in sorted(results.items())] == [
        (PARSED, None),
        (PARSED, None),
        (LIMIT_EXCEEDED, "parsed_files"),
    ]


def test_a_parse_that_exceeds_its_time_budget_is_parse_timeout(tmp_path: Path, settings: Settings) -> None:
    # About 1 MiB of statements takes far longer than 1 ms to parse.
    (tmp_path / "slow.py").write_text("value = call(a, b, c)\n" * 45_000)
    (tmp_path / "zzz.py").write_text("x = 1\n")
    started = time.monotonic()
    results = run(tmp_path, replace(settings, parse_timeout_ms=1, max_file_bytes=1024 * 1024))
    assert time.monotonic() - started < 10
    assert results["slow.py"].status == PARSE_TIMEOUT
    assert results["slow.py"].parse_dict()["ast_nodes"] is None
    # The parser is reset and keeps working for the next file.
    assert results["zzz.py"].status in (PARSED, PARSE_TIMEOUT)


def test_an_expired_run_deadline_fails_the_run_with_analysis_timeout(tmp_path: Path, settings: Settings) -> None:
    (tmp_path / "a.py").write_text("x = 1\n")
    expired = Deadline(-1)
    with pytest.raises(AnalyzerError) as caught:
        run(tmp_path, settings, deadline=expired)
    assert caught.value.code is ErrorCode.ANALYSIS_TIMEOUT


def test_the_walk_checks_the_deadline(tmp_path: Path, settings: Settings, monkeypatch: pytest.MonkeyPatch) -> None:
    (tmp_path / "a.py").write_text("x = 1\n" * 50)
    monkeypatch.setattr(walker_module, "DEADLINE_CHECK_INTERVAL", 10)

    class Expiring(Deadline):
        def __init__(self) -> None:
            super().__init__(30)
            self.checks = 0

        def check(self) -> None:
            self.checks += 1
            if self.checks > 3:
                raise AnalyzerError(ErrorCode.ANALYSIS_TIMEOUT)

    with pytest.raises(AnalyzerError):
        run(tmp_path, settings, deadline=Expiring())


def test_pathologically_deep_nesting_is_walked_without_recursion(tmp_path: Path, settings: Settings) -> None:
    depth = 20_000
    (tmp_path / "deep.py").write_text("x = " + "(" * depth + "1" + ")" * depth + "\n")
    (tmp_path / "deep.js").write_text("function f() {" + "if (a) {" * 2_000 + "}" * 2_000 + "}\n")
    results = run(tmp_path, replace(settings, max_ast_nodes=1_000_000))
    assert results["deep.py"].status == PARSED
    assert results["deep.py"].ast_max_depth is not None and results["deep.py"].ast_max_depth > depth
    structure = results["deep.js"].structure_dict()
    assert structure is not None
    assert structure["functions"][0]["max_nesting"] == 2_000
    assert structure["functions"][0]["complexity"] == 2_001


def test_the_limits_are_validated_at_startup(settings: Settings) -> None:
    from app.config import ConfigurationError

    for field in ("parse_timeout_ms", "max_ast_nodes", "max_total_ast_nodes", "max_parsed_files"):
        with pytest.raises(ConfigurationError):
            replace(settings, **{field: 0})
    with pytest.raises(ConfigurationError):
        replace(settings, max_ast_nodes=10, max_total_ast_nodes=5)
