"""Bounded parsing of the discovered files of one run.

Every analyzable file (IR 1.0 ``skip_reason`` null) gets exactly one parse
status:

- ``PARSED``: a syntax tree without error or missing nodes; full IR 1.1
  structure is extracted.
- ``PARSE_ERROR``: the grammar recovered from syntax errors. The error-node
  count and the first error position are reported; no structure or metrics
  are derived from a tree that contains errors.
- ``PARSE_TIMEOUT``: parsing the file took longer than
  ANALYZER_PARSE_TIMEOUT_MS (Tree-sitter's own timeout aborts it).
- ``LIMIT_EXCEEDED``: a deterministic limit was reached (``limit`` names it):
  ``ast_nodes`` (the file's tree has more than ANALYZER_MAX_AST_NODES
  nodes), ``total_ast_nodes`` (the run's budget ANALYZER_MAX_TOTAL_AST_NODES
  is used up) or ``parsed_files`` (ANALYZER_MAX_PARSED_FILES files were
  already parsed).
- ``UNSUPPORTED_PARSER``: no grammar is registered for the language.

One malformed file never fails the run. The run's hard deadline still
applies: when it expires the whole run fails with ANALYSIS_TIMEOUT, as in
Phase 08. Files are processed in path order, so every deterministic limit
cuts at the same file on every run.

Nothing is executed: Tree-sitter only reads the bytes. Files are opened with
O_NOFOLLOW inside the run's private workspace and read up to
ANALYZER_MAX_FILE_BYTES.
"""

import os
import warnings
from dataclasses import dataclass
from typing import Any

import tree_sitter

from app.config import Settings
from app.deadline import Deadline
from app.discovery.discover import FileRecord
from app.errors import AnalyzerError, ErrorCode
from app.parsing.grammars import language
from app.parsing.specs import SPECS, grammar_for
from app.parsing.walker import WalkResult, walk

PARSED = "PARSED"
PARSE_ERROR = "PARSE_ERROR"
PARSE_TIMEOUT = "PARSE_TIMEOUT"
LIMIT_EXCEEDED = "LIMIT_EXCEEDED"
UNSUPPORTED_PARSER = "UNSUPPORTED_PARSER"
STATUSES = (PARSED, PARSE_ERROR, PARSE_TIMEOUT, LIMIT_EXCEEDED, UNSUPPORTED_PARSER)

# Parser.timeout_micros is deprecated upstream in favour of a progress
# callback, which crashes the 0.25/0.26 Python bindings (segmentation fault),
# so the analyzer keeps the working timeout; see docs/architecture/analyzer.md
# "Parser resource limits". The filter is installed once, at import, because
# warnings.catch_warnings() is not safe with concurrent runs in threads.
warnings.filterwarnings("ignore", message="Use the progress_callback in parse", category=DeprecationWarning)

LIMIT_AST_NODES = "ast_nodes"
LIMIT_TOTAL_AST_NODES = "total_ast_nodes"
LIMIT_PARSED_FILES = "parsed_files"


@dataclass(frozen=True)
class ParsedFile:
    """IR 1.1 additions for one analyzable file."""

    language: str
    status: str
    limit: str | None = None
    ast_nodes: int | None = None
    ast_max_depth: int | None = None
    error_nodes: int | None = None
    first_error: tuple[int, int] | None = None
    walk: WalkResult | None = None

    def parse_dict(self) -> dict[str, Any]:
        return {
            "status": self.status,
            "limit": self.limit,
            "ast_nodes": self.ast_nodes,
            "ast_max_depth": self.ast_max_depth,
            "error_nodes": self.error_nodes,
            "first_error": None if self.first_error is None else {"line": self.first_error[0], "column": self.first_error[1]},
        }

    def structure_dict(self) -> dict[str, Any] | None:
        return None if self.walk is None or self.status != PARSED else self.walk.structure()


def parse_files(root: str, records: list[FileRecord], settings: Settings, deadline: Deadline) -> dict[str, ParsedFile]:
    """Parses every analyzable record (in path order). Keys are IR paths."""
    parsers: dict[str, tree_sitter.Parser] = {}
    results: dict[str, ParsedFile] = {}
    parsed_files = 0
    total_nodes = 0

    for record in sorted(records, key=lambda item: item.path):
        if record.skip_reason is not None or record.language is None:
            continue
        deadline.check()
        grammar = grammar_for(record.language, record.extension)
        if grammar is None:
            results[record.path] = ParsedFile(record.language, UNSUPPORTED_PARSER)
            continue
        if parsed_files >= settings.max_parsed_files:
            results[record.path] = ParsedFile(record.language, LIMIT_EXCEEDED, limit=LIMIT_PARSED_FILES)
            continue
        if total_nodes >= settings.max_total_ast_nodes:
            results[record.path] = ParsedFile(record.language, LIMIT_EXCEEDED, limit=LIMIT_TOTAL_AST_NODES)
            continue

        source = _read(os.path.join(root, *record.path.split("/")), settings.max_file_bytes)
        parser = parsers.get(grammar)
        if parser is None:
            parser = parsers[grammar] = tree_sitter.Parser(language(grammar))
        parsed_files += 1

        tree = _parse(parser, source, settings, deadline)
        if tree is None:
            results[record.path] = ParsedFile(record.language, PARSE_TIMEOUT)
            continue

        nodes = tree.root_node.descendant_count
        if nodes > settings.max_ast_nodes:
            results[record.path] = ParsedFile(record.language, LIMIT_EXCEEDED, limit=LIMIT_AST_NODES, ast_nodes=nodes)
            continue
        if total_nodes + nodes > settings.max_total_ast_nodes:
            total_nodes = settings.max_total_ast_nodes
            results[record.path] = ParsedFile(record.language, LIMIT_EXCEEDED, limit=LIMIT_TOTAL_AST_NODES, ast_nodes=nodes)
            continue
        total_nodes += nodes

        walked = walk(tree, source, SPECS[record.language], record.lines or 0, deadline)
        status = PARSE_ERROR if tree.root_node.has_error else PARSED
        results[record.path] = ParsedFile(
            record.language,
            status,
            ast_nodes=nodes,
            ast_max_depth=walked.ast_max_depth,
            error_nodes=walked.error_nodes if status == PARSE_ERROR else 0,
            first_error=walked.first_error if status == PARSE_ERROR else None,
            walk=walked if status == PARSED else None,
        )
    return results


def _read(path: str, max_bytes: int) -> bytes:
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_CLOEXEC", 0))
    with os.fdopen(descriptor, "rb") as handle:
        return handle.read(max_bytes)


def _parse(parser: tree_sitter.Parser, source: bytes, settings: Settings, deadline: Deadline) -> tree_sitter.Tree | None:
    """Parses with a time budget: the per-file limit, or less when the run deadline is closer.

    Returns None when the per-file budget ran out (PARSE_TIMEOUT); raises
    ANALYSIS_TIMEOUT when the run deadline did.
    """
    remaining = deadline.remaining()
    if remaining <= 0:
        raise AnalyzerError(ErrorCode.ANALYSIS_TIMEOUT)
    file_budget = settings.parse_timeout_ms / 1000
    budget = min(file_budget, remaining)
    parser.reset()
    parser.timeout_micros = max(int(budget * 1_000_000), 1)  # type: ignore[misc]  # the stub types the deprecated setter as read-only
    try:
        return parser.parse(source)
    except ValueError:
        # The Tree-sitter binding reports an aborted parse this way; with a
        # language always set, the only cause is the timeout.
        parser.reset()
        if budget < file_budget:
            raise AnalyzerError(ErrorCode.ANALYSIS_TIMEOUT) from None
        return None
