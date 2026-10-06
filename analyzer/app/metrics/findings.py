"""Deterministic structural findings, rule set version 1.0 (metrics 1.0).

A finding is a threshold violation measured on IR 1.1. It carries a
stable ``rule_id``, a fixed severity, the file path, the declaration's
position, the measured ``value`` and the rule's ``threshold``. Messages are
built only from those numbers and fixed words: never from names, source
text or snippets, and never by an AI model. There is no aggregate "quality
score".

Findings are sorted by (path, line, column, rule_id). At most MAX_FINDINGS
are returned; ``total`` and ``by_rule`` always count all of them and
``truncated`` says whether items were cut.
"""

from dataclasses import dataclass
from typing import Any

from app.discovery.discover import FileRecord
from app.metrics.aggregate import COMPLEXITY_THRESHOLD
from app.parsing.parser import PARSE_ERROR, PARSED, ParsedFile

MAX_FINDINGS = 2000


@dataclass(frozen=True)
class Rule:
    rule_id: str
    severity: str
    threshold: int | None
    description: str


CYCLOMATIC = Rule("complexity/cyclomatic", "medium", COMPLEXITY_THRESHOLD, "Function cyclomatic complexity above the threshold")
NESTING = Rule("structure/nesting-depth", "medium", 4, "Function control-flow nesting deeper than the threshold")
FUNCTION_LENGTH = Rule("structure/function-length", "low", 100, "Function spans more lines than the threshold")
PARAMETERS = Rule("structure/parameter-count", "low", 5, "Function declares more parameters than the threshold")
CLASS_LENGTH = Rule("structure/class-length", "low", 500, "Type declaration spans more lines than the threshold")
SYNTAX_ERROR = Rule("parse/syntax-error", "medium", None, "File does not parse without syntax errors")

RULES = (CYCLOMATIC, NESTING, FUNCTION_LENGTH, PARAMETERS, CLASS_LENGTH, SYNTAX_ERROR)

_KIND_WORDS = {"function": "Function", "method": "Method", "anonymous": "Anonymous function"}
_TYPE_WORDS = {"class": "Class", "interface": "Interface", "trait": "Trait", "enum": "Enum", "struct": "Struct"}


def compute_findings(records: list[FileRecord], parsed: dict[str, ParsedFile]) -> dict[str, Any]:
    items: list[dict[str, Any]] = []
    for record in records:
        result = parsed.get(record.path)
        if result is None:
            continue
        if result.status == PARSE_ERROR:
            line, column = result.first_error or (None, None)
            count = result.error_nodes or 0
            items.append(
                _finding(SYNTAX_ERROR, record.path, line, column, count, f"File has {count} syntax error node(s) and was not measured.")
            )
        structure = result.structure_dict()
        if result.status != PARSED or structure is None:
            continue
        for function in structure["functions"]:
            items += _function_findings(record.path, function)
        for declared in structure["types"]:
            length = declared["end_line"] - declared["line"] + 1
            if length > (CLASS_LENGTH.threshold or 0):
                word = _TYPE_WORDS.get(declared["kind"], "Type")
                items.append(
                    _finding(
                        CLASS_LENGTH,
                        record.path,
                        declared["line"],
                        declared["column"],
                        length,
                        _message(word, "spans", length, "lines", CLASS_LENGTH),
                    )
                )

    items.sort(key=lambda item: (item["path"], item["line"] or 0, item["column"] or 0, item["rule_id"]))
    by_rule = {rule.rule_id: 0 for rule in RULES}
    for item in items:
        by_rule[item["rule_id"]] += 1
    return {
        "total": len(items),
        "by_rule": by_rule,
        "truncated": len(items) > MAX_FINDINGS,
        "items": items[:MAX_FINDINGS],
    }


def _function_findings(path: str, function: dict[str, Any]) -> list[dict[str, Any]]:
    word = _KIND_WORDS.get(function["kind"], "Function")
    line, column = function["line"], function["column"]
    length = function["end_line"] - function["line"] + 1
    checks = (
        (CYCLOMATIC, function["complexity"], "has cyclomatic complexity", ""),
        (NESTING, function["max_nesting"], "has nesting depth", ""),
        (FUNCTION_LENGTH, length, "spans", "lines"),
        (PARAMETERS, function["parameters"], "declares", "parameters"),
    )
    found: list[dict[str, Any]] = []
    for rule, value, verb, unit in checks:
        if value > (rule.threshold or 0):
            found.append(_finding(rule, path, line, column, value, _message(word, verb, value, unit, rule)))
    return found


def _message(subject: str, verb: str, value: int, unit: str, rule: Rule) -> str:
    measured = f"{value} {unit}".strip()
    return f"{subject} {verb} {measured} (threshold {rule.threshold})."


def _finding(rule: Rule, path: str, line: int | None, column: int | None, value: int, message: str) -> dict[str, Any]:
    return {
        "rule_id": rule.rule_id,
        "severity": rule.severity,
        "path": path,
        "line": line,
        "column": column,
        "value": value,
        "threshold": rule.threshold,
        "message": message,
    }
