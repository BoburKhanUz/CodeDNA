"""The analysis pipeline. Two result types, chosen by options.result_type:

    FOUNDATION (Phase 08, default)
        validate source URL -> download (bounded) -> verify size/SHA-256
        -> safe extraction -> discovery -> foundation result (IR 1.0)
           |
           v
    STATIC ANALYSIS (Phase 09) = the foundation steps, then
        -> bounded AST parsing (IR 1.1) -> static metrics and structural
           findings -> static_analysis result (foundation fields unchanged)
           |
           v
    future: DNA / competency analysis (Phase 11+)

Runs in a worker thread with a hard deadline (ANALYZER_HARD_TIMEOUT_SECONDS)
inside a fresh workspace that is removed on every exit path. Parsing reads
files only while the workspace exists; nothing is executed. Produces no
features, DNA scores or AI output: scoring arrives in Phase 11.
"""

import logging
import os
from collections.abc import Callable
from typing import Any

from app.archive.extract import extract_archive
from app.canonical import canonical_hash, canonical_json
from app.config import Settings
from app.contracts.request import AnalyzeRequest
from app.deadline import Deadline
from app.discovery.discover import SKIP_BINARY, SKIP_TOO_LARGE, SKIP_UNSUPPORTED_LANGUAGE, Discovery, discover
from app.errors import AnalyzerError, ErrorCode
from app.metrics.aggregate import METRICS_VERSION, compute_metrics
from app.metrics.findings import compute_findings
from app.parsing.grammars import GRAMMARS, RUNTIME
from app.parsing.parser import STATUSES, ParsedFile, parse_files
from app.parsing.specs import SPECS
from app.source.download import download
from app.source.url_policy import Resolver, SourceTarget, system_resolver, validate_source_url
from app.versions import (
    ANALYZER_VERSION,
    CONTRACT_VERSION,
    IR_VERSION,
    IR_VERSION_STATIC_ANALYSIS,
    RESULT_TYPE_FOUNDATION,
    RESULT_TYPE_STATIC_ANALYSIS,
)
from app.workspace.workspace import run_workspace

logger = logging.getLogger("codedna.analyzer")

# Fetches the archive for a validated target into the given file path.
Fetcher = Callable[[SourceTarget, str, AnalyzeRequest, Settings, Deadline], None]

# Sections excluded from result_hash (contract section 4).
UNHASHED_FIELDS = ("request_id", "diagnostics", "result_hash")


def fetch_with_download(target: SourceTarget, destination: str, request: AnalyzeRequest, settings: Settings, deadline: Deadline) -> None:
    download(
        target,
        destination,
        expected_size=request.source.size_bytes,
        expected_sha256=request.source.sha256,
        settings=settings,
        deadline=deadline,
    )


def request_fingerprint(request: AnalyzeRequest, settings: Settings) -> str:
    """Identity of a run's input. The URL is excluded: it is re-signed per attempt."""
    return canonical_hash(
        {
            "analysis_run_id": request.analysis_run_id,
            "contract_major": request.contract_version.split(".")[0],
            "source": {
                "type": request.source.type,
                "format": request.source.format,
                "sha256": request.source.sha256,
                "size_bytes": request.source.size_bytes,
            },
            "result_type": request.result_type(),
            "analysis": analysis_configuration(request, settings),
        }
    )


def analysis_configuration(request: AnalyzeRequest, settings: Settings) -> dict[str, Any]:
    """Everything besides the source bytes and versions that shapes a result.

    It is part of the result (and therefore of result_hash): a different
    configuration visibly produces a different hash. A foundation result
    records exactly the Phase 08 configuration; a static_analysis result also
    records the parser limits, which shape it.
    """
    configuration: dict[str, Any] = {
        "languages": list(request.requested_languages()),
        "ignored_directories": sorted(settings.ignored_directories),
        "max_file_bytes": settings.max_file_bytes,
    }
    if request.result_type() == RESULT_TYPE_STATIC_ANALYSIS:
        configuration.update(
            {
                "parse_timeout_ms": settings.parse_timeout_ms,
                "max_ast_nodes": settings.max_ast_nodes,
                "max_total_ast_nodes": settings.max_total_ast_nodes,
                "max_parsed_files": settings.max_parsed_files,
            }
        )
    return configuration


def analyze(
    request: AnalyzeRequest,
    settings: Settings,
    *,
    resolver: Resolver = system_resolver,
    fetcher: Fetcher = fetch_with_download,
) -> dict[str, Any]:
    """Returns the deterministic part of the result (no request_id/diagnostics)."""
    deadline = Deadline(settings.hard_timeout_seconds)
    # Limits are enforced here as well as in the downloader, whatever fetches the bytes.
    if request.source.size_bytes > settings.max_archive_bytes:
        raise AnalyzerError(ErrorCode.SOURCE_TOO_LARGE, {"limit_bytes": settings.max_archive_bytes})
    target = validate_source_url(request.source.url, settings, resolver)

    with run_workspace(settings.workspace_root) as workspace:
        archive_path = os.path.join(workspace, "source.zip")
        source_root = os.path.join(workspace, "source")
        os.mkdir(source_root, mode=0o700)

        fetcher(target, archive_path, request, settings, deadline)
        deadline.check()
        if os.path.getsize(archive_path) > settings.max_archive_bytes:
            raise AnalyzerError(ErrorCode.SOURCE_TOO_LARGE, {"limit_bytes": settings.max_archive_bytes})
        extracted = extract_archive(archive_path, source_root, settings, deadline)
        # The archive is no longer needed; free its space before discovery.
        os.unlink(archive_path)

        found = discover(
            source_root,
            ignored_directories=settings.ignored_directories,
            languages=request.requested_languages(),
            max_file_bytes=settings.max_file_bytes,
            deadline=deadline,
        )
        if not found.analyzable:
            raise AnalyzerError(ErrorCode.NO_SUPPORTED_FILES)
        # Parsing reads the files, so it runs while the workspace exists.
        parsed = parse_files(source_root, found.files, settings, deadline) if request.result_type() == RESULT_TYPE_STATIC_ANALYSIS else None

    foundation = build_result(request, settings, found, extracted.files, extracted.bytes)
    if parsed is None:
        return foundation
    return build_static_analysis_result(foundation, found, parsed)


def build_result(
    request: AnalyzeRequest,
    settings: Settings,
    found: Discovery,
    files_total: int,
    bytes_total: int,
) -> dict[str, Any]:
    """The Phase 08 foundation result (contract 1.0, IR 1.0), unchanged."""
    analyzable = found.analyzable
    skipped = {SKIP_UNSUPPORTED_LANGUAGE: 0, SKIP_TOO_LARGE: 0, SKIP_BINARY: 0}
    for record in found.files:
        if record.skip_reason is not None:
            skipped[record.skip_reason] += 1

    languages: dict[str, dict[str, int]] = {}
    for record in analyzable:
        summary = languages.setdefault(str(record.language), {"files": 0, "bytes": 0, "lines": 0})
        summary["files"] += 1
        summary["bytes"] += record.size_bytes
        summary["lines"] += record.lines or 0

    result: dict[str, Any] = {
        "contract_version": CONTRACT_VERSION,
        "result_type": RESULT_TYPE_FOUNDATION,
        "analysis_run_id": request.analysis_run_id,
        "versions": {
            "analyzer": ANALYZER_VERSION,
            "ir": IR_VERSION,
            # Not produced by the foundation (Phases 09 and 11).
            "metrics": None,
            "scoring": None,
            "parsers": {},
        },
        "analysis": analysis_configuration(request, settings),
        "source": {
            "sha256": request.source.sha256,
            "size_bytes": request.source.size_bytes,
            "files_total": files_total,
            "files_analyzed": len(analyzable),
            "files_skipped": {"ignored_path": found.ignored_files, **skipped},
            "bytes_total": bytes_total,
        },
        "languages": [{"language": language, **summary} for language, summary in sorted(languages.items())],
        "ir": {
            "version": IR_VERSION,
            "files": [record.to_dict() for record in found.files],
        },
    }
    result["result_hash"] = result_hash(result)
    return result


def build_static_analysis_result(foundation: dict[str, Any], found: Discovery, parsed: dict[str, ParsedFile]) -> dict[str, Any]:
    """The Phase 09 static_analysis result: the foundation result with every
    field kept, plus parsing, IR 1.1, metrics and findings (additive only).

    The foundation's own result_hash is not reused: the new result is hashed
    as a whole, under the same rule.
    """
    result: dict[str, Any] = {key: value for key, value in foundation.items() if key != "result_hash"}
    result["result_type"] = RESULT_TYPE_STATIC_ANALYSIS
    result["versions"] = {
        **foundation["versions"],
        "ir": IR_VERSION_STATIC_ANALYSIS,
        "metrics": METRICS_VERSION,
        "parser_runtime": RUNTIME,
        "parsers": parser_versions(tuple(foundation["analysis"]["languages"])),
    }
    result["parsing"] = parsing_summary(found, parsed)
    result["ir"] = {
        "version": IR_VERSION_STATIC_ANALYSIS,
        "files": [ir_file(record, parsed.get(record["path"])) for record in foundation["ir"]["files"]],
    }
    result["metrics"] = compute_metrics(found.files, parsed)
    result["findings"] = compute_findings(found.files, parsed)
    result["result_hash"] = result_hash(result)
    return result


def parser_versions(languages: tuple[str, ...]) -> dict[str, str]:
    """Grammar package and version per requested language (TypeScript: both dialects)."""
    versions: dict[str, str] = {}
    for language in sorted(languages):
        spec = SPECS.get(language)
        if spec is not None:
            versions[language] = GRAMMARS[spec.grammar].label
    return versions


def ir_file(record: dict[str, Any], parsed: ParsedFile | None) -> dict[str, Any]:
    """IR 1.1 file record: every IR 1.0 field unchanged, plus ``parse`` and ``structure``."""
    return {
        **record,
        "parse": None if parsed is None else parsed.parse_dict(),
        "structure": None if parsed is None else parsed.structure_dict(),
    }


def parsing_summary(found: Discovery, parsed: dict[str, ParsedFile]) -> dict[str, Any]:
    totals = dict.fromkeys(STATUSES, 0)
    languages: dict[str, dict[str, int]] = {}
    for record in found.analyzable:
        result = parsed.get(record.path)
        if result is None:
            continue
        totals[result.status] += 1
        counts = languages.setdefault(result.language, dict.fromkeys(STATUSES, 0))
        counts[result.status] += 1
    return {
        "files": totals,
        "languages": [{"language": language, "files": counts} for language, counts in sorted(languages.items())],
    }


def result_hash(result: dict[str, Any]) -> str:
    """SHA-256 of the canonical JSON of every top-level field except
    request_id, diagnostics and result_hash (app.canonical)."""
    return canonical_hash({key: value for key, value in result.items() if key not in UNHASHED_FIELDS})


def result_size(result: dict[str, Any]) -> int:
    return len(canonical_json(result))
