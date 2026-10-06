"""POST /internal/v1/analyze (contract section 4).

Order of checks:
 1. request body size bound (before reading it fully)
 2. HMAC signature and timestamp (unsigned error responses)
 3. replay of the same X-Request-ID
 4. body parsed and validated; Idempotency-Key must equal analysis_run_id
 5. completed-result cache, in-flight/conflict check, concurrency limit
 6. the pipeline in a worker thread, under the hard deadline

Every response after step 2 succeeded is signed (X-CodeDNA-Timestamp,
X-CodeDNA-Signature), errors included.
"""

import logging
import time
from typing import Any

from fastapi import APIRouter, Request
from fastapi.responses import Response
from starlette.concurrency import run_in_threadpool

from app.auth.hmac_signing import sign_response, verify_request
from app.canonical import canonical_json
from app.config import Settings
from app.contracts.request import parse_request
from app.errors import AnalyzerError, ErrorCode, error_body
from app.services import analysis
from app.versions import ANALYZER_VERSION

router = APIRouter()
logger = logging.getLogger("codedna.analyzer")

PATH = "/internal/v1/analyze"


@router.post(PATH)
async def analyze(request: Request) -> Response:
    settings: Settings = request.app.state.settings
    request_id: str = request.state.request_id
    started = time.monotonic()
    path = _signed_path(request)

    declared = request.headers.get("content-length", "")
    if declared.isdigit() and int(declared) > settings.max_request_body_bytes:
        return _error(ErrorCode.INVALID_REQUEST, request_id, {"reason": "body_too_large"})
    body = await request.body()
    if len(body) > settings.max_request_body_bytes:
        return _error(ErrorCode.INVALID_REQUEST, request_id, {"reason": "body_too_large"})

    try:
        signed_at = verify_request(
            secrets=settings.secrets,
            max_skew_seconds=settings.hmac_max_skew_seconds,
            method=request.method,
            path=path,
            body=body,
            timestamp=request.headers.get("x-codedna-timestamp"),
            signature=request.headers.get("x-codedna-signature"),
            # The signed request ID is the raw header (the middleware only
            # replaces invalid ones for the response).
            request_id=request.headers.get("x-request-id"),
        )
    except AnalyzerError as error:
        _log("Analyze request rejected.", request_id, None, error.code, started)
        return _error(error.code, request_id, error.details)

    # From here on, every response is signed.
    run_id: str | None = None
    attempt: int | None = None
    try:
        request.app.state.replay_cache.remember(request_id, signed_at)
        analyze_request = parse_request(body)
        run_id, attempt = analyze_request.analysis_run_id, analyze_request.attempt
        if request.headers.get("idempotency-key") != analyze_request.analysis_run_id:
            raise AnalyzerError(ErrorCode.INVALID_REQUEST, {"fields": ["Idempotency-Key"]})

        registry = request.app.state.registry
        fingerprint = analysis.request_fingerprint(analyze_request, settings)
        result = registry.cached_result(run_id, fingerprint)
        replayed = result is not None
        if result is None:
            with registry.claim(run_id, fingerprint):
                result = await run_in_threadpool(
                    analysis.analyze,
                    analyze_request,
                    settings,
                    resolver=request.app.state.resolver,
                    fetcher=request.app.state.fetcher,
                )
            registry.store(run_id, fingerprint, result, analysis.result_size(result))
    except AnalyzerError as error:
        _log("Analysis failed.", request_id, run_id, error.code, started, attempt=attempt)
        return _signed(settings, path, request_id, error.code.status, error_body(error.code, request_id, error.details), error.headers)
    except Exception as exc:  # noqa: BLE001 - every failure must become a safe, signed error
        logger.error(
            "Unexpected analyzer error.",
            extra={"request_id": request_id, "analysis_run_id": run_id, "exception_type": type(exc).__name__},
        )
        code = ErrorCode.INTERNAL_ERROR
        return _signed(settings, path, request_id, code.status, error_body(code, request_id))

    duration_ms = int((time.monotonic() - started) * 1000)
    payload = {**result, "request_id": request_id, "diagnostics": {"duration_ms": duration_ms, "replayed": replayed}}
    logger.info(
        "Analysis completed.",
        extra={
            "request_id": request_id,
            "analysis_run_id": run_id,
            "attempt": attempt,
            "status": 200,
            "duration_ms": duration_ms,
            "files_total": result["source"]["files_total"],
            "files_analyzed": result["source"]["files_analyzed"],
            "bytes_total": result["source"]["bytes_total"],
            "result_hash": result["result_hash"],
            "analyzer_version": ANALYZER_VERSION,
        },
    )
    headers = {"Idempotent-Replayed": "true"} if replayed else {}
    return _signed(settings, path, request_id, 200, payload, headers)


def _signed_path(request: Request) -> str:
    raw = request.scope.get("raw_path") or request.url.path.encode()
    query = request.scope.get("query_string", b"")
    path = raw.decode("latin-1")
    return path + ("?" + query.decode("latin-1") if query else "")


def _signed(
    settings: Settings, path: str, request_id: str, status: int, payload: dict[str, Any], headers: dict[str, str] | None = None
) -> Response:
    body = canonical_json(payload)
    signature = sign_response(settings.hmac_secret, status, path, request_id, body)
    return Response(content=body, status_code=status, media_type="application/json", headers={**(headers or {}), **signature})


def _error(code: ErrorCode, request_id: str, details: dict[str, Any] | None = None) -> Response:
    return Response(content=canonical_json(error_body(code, request_id, details)), status_code=code.status, media_type="application/json")


def _log(message: str, request_id: str, run_id: str | None, code: ErrorCode, started: float, attempt: int | None = None) -> None:
    logger.info(
        message,
        extra={
            "request_id": request_id,
            "analysis_run_id": run_id,
            "attempt": attempt,
            "status": code.status,
            "error_code": code.name,
            "duration_ms": int((time.monotonic() - started) * 1000),
        },
    )
