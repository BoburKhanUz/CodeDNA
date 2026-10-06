"""Static metrics, version 1.0 (docs/architecture/metrics-v1.md).

Every metric is computed from the IR 1.1 records of the run, never from
source text and never by an AI model. Structural metrics (lines, AST,
declarations, functions, complexity) come from PARSED files only: a tree
with syntax errors is reported, not measured.

Values are integers, or for averages a number rounded half-to-even to four
decimal places using Decimal arithmetic, so results are identical on every
platform. A value is ``null`` when it cannot be computed: an average or
maximum over zero functions, or a metric the language's adapter does not
support. Unsupported metrics are also listed under ``unsupported``.
"""

from collections import Counter
from collections.abc import Iterable
from decimal import ROUND_HALF_EVEN, Decimal
from typing import Any

from app.discovery.discover import FileRecord
from app.parsing.parser import LIMIT_EXCEEDED, PARSE_ERROR, PARSE_TIMEOUT, PARSED, UNSUPPORTED_PARSER, ParsedFile
from app.parsing.specs import SPECS

METRICS_VERSION = "1.0"

# Functions whose cyclomatic complexity exceeds this are counted in
# complexity_over_threshold (McCabe's recommended upper bound).
COMPLEXITY_THRESHOLD = 10

_PLACES = Decimal("0.0001")

_FILE_STATUS_KEYS = {
    PARSED: "files_parsed",
    PARSE_ERROR: "files_parse_error",
    PARSE_TIMEOUT: "files_parse_timeout",
    LIMIT_EXCEEDED: "files_limit_exceeded",
    UNSUPPORTED_PARSER: "files_unsupported_parser",
}


def average(total: int, count: int) -> float | None:
    if count == 0:
        return None
    return float((Decimal(total) / Decimal(count)).quantize(_PLACES, rounding=ROUND_HALF_EVEN))


def unsupported_metrics(language: str) -> list[str]:
    spec = SPECS.get(language)
    if spec is None:
        return []
    return [] if spec.supports_bases else ["types_with_bases"]


def compute_metrics(records: list[FileRecord], parsed: dict[str, ParsedFile]) -> dict[str, Any]:
    by_language: dict[str, list[tuple[FileRecord, ParsedFile]]] = {}
    for record in records:
        result = parsed.get(record.path)
        if record.skip_reason is None and record.language is not None and result is not None:
            by_language.setdefault(record.language, []).append((record, result))

    every = [item for language in sorted(by_language) for item in by_language[language]]
    overall_unsupported = sorted({key for language in by_language for key in unsupported_metrics(language)})
    return {
        "version": METRICS_VERSION,
        "overall": _group(every, overall_unsupported),
        "by_language": {language: _group(items, unsupported_metrics(language)) for language, items in sorted(by_language.items())},
    }


def _group(items: Iterable[tuple[FileRecord, ParsedFile]], unsupported: list[str]) -> dict[str, Any]:
    statuses: Counter[str] = Counter()
    lines = {"total": 0, "code": 0, "comment": 0, "blank": 0}
    ast_nodes = 0
    ast_max_depth: int | None = None
    imports = 0
    types_by_kind: Counter[str] = Counter()
    types_with_bases = 0
    kinds: Counter[str] = Counter()
    function_lines: list[int] = []
    parameters: list[int] = []
    nesting: list[int] = []
    complexity: list[int] = []
    files = 0

    for _record, result in items:
        files += 1
        statuses[result.status] += 1
        structure = result.structure_dict()
        if result.status != PARSED or structure is None:
            continue
        for key in lines:
            lines[key] += structure["lines"][key]
        ast_nodes += result.ast_nodes or 0
        if result.ast_max_depth is not None:
            ast_max_depth = max(ast_max_depth or 0, result.ast_max_depth)
        imports += len(structure["imports"])
        for declared in structure["types"]:
            types_by_kind[declared["kind"]] += 1
            if declared["bases"]:
                types_with_bases += 1
        for function in structure["functions"]:
            kinds[function["kind"]] += 1
            function_lines.append(function["end_line"] - function["line"] + 1)
            parameters.append(function["parameters"])
            nesting.append(function["max_nesting"])
            complexity.append(function["complexity"])

    count = len(complexity)
    metrics: dict[str, Any] = {
        "files_analyzable": files,
        **{key: statuses[status] for status, key in _FILE_STATUS_KEYS.items()},
        "lines_total": lines["total"],
        "lines_code": lines["code"],
        "lines_comment": lines["comment"],
        "lines_blank": lines["blank"],
        "ast_nodes": ast_nodes,
        "ast_max_depth": ast_max_depth,
        "imports": imports,
        "types": sum(types_by_kind.values()),
        "types_by_kind": dict(sorted(types_by_kind.items())),
        "types_with_bases": None if "types_with_bases" in unsupported else types_with_bases,
        "functions_total": count,
        "functions": kinds["function"],
        "methods": kinds["method"],
        "anonymous_functions": kinds["anonymous"],
        "function_lines_avg": average(sum(function_lines), count),
        "function_lines_max": max(function_lines, default=None),
        "parameters_avg": average(sum(parameters), count),
        "parameters_max": max(parameters, default=None),
        "nesting_avg": average(sum(nesting), count),
        "nesting_max": max(nesting, default=None),
        "complexity_total": sum(complexity),
        "complexity_avg": average(sum(complexity), count),
        "complexity_max": max(complexity, default=None),
        "complexity_threshold": COMPLEXITY_THRESHOLD,
        "complexity_over_threshold": sum(1 for value in complexity if value > COMPLEXITY_THRESHOLD),
        "unsupported": unsupported,
    }
    return metrics
