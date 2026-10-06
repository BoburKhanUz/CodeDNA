"""FastAPI application (internal-only service).

``create_app`` validates the configuration (the process refuses to start
without a valid HMAC secret, source allow-list and limits), removes stale
run workspaces, and wires the routes. Interactive docs and the OpenAPI
document are disabled; the contract is docs/api/internal-analyzer-contract.md.
"""

import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import Response
from starlette.exceptions import HTTPException

from app import __version__
from app.api import analyze, health
from app.api.request_id import RequestIdMiddleware
from app.auth.replay import ReplayCache
from app.canonical import canonical_json
from app.config import Settings
from app.errors import ErrorCode, error_body
from app.logging_setup import configure_logging
from app.services.analysis import Fetcher, fetch_with_download
from app.services.registry import RunRegistry
from app.source.url_policy import Resolver, system_resolver
from app.workspace.workspace import ensure_root, sweep_stale

logger = logging.getLogger("codedna.analyzer")


def create_app(
    settings: Settings | None = None,
    *,
    resolver: Resolver = system_resolver,
    fetcher: Fetcher = fetch_with_download,
) -> FastAPI:
    settings = settings or Settings.from_env()
    configure_logging(settings.log_level)

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        ensure_root(settings.workspace_root)
        removed = sweep_stale(settings.workspace_root)
        if removed:
            logger.warning("Removed stale run workspaces.", extra={"removed": removed})
        yield

    app = FastAPI(
        title="CodeDNA Analyzer",
        version=__version__,
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
        lifespan=lifespan,
    )
    app.state.settings = settings
    app.state.replay_cache = ReplayCache(settings.replay_cache_entries, settings.hmac_max_skew_seconds)
    app.state.registry = RunRegistry(settings.max_concurrency, settings.result_cache_bytes, settings.result_cache_seconds)
    app.state.resolver = resolver
    app.state.fetcher = fetcher

    app.add_middleware(RequestIdMiddleware)
    app.include_router(health.router)
    app.include_router(analyze.router)

    @app.exception_handler(HTTPException)
    async def http_error(request: Request, exc: HTTPException) -> Response:
        code = {404: ErrorCode.NOT_FOUND, 405: ErrorCode.METHOD_NOT_ALLOWED}.get(exc.status_code, ErrorCode.INVALID_REQUEST)
        return _error(request, code)

    @app.exception_handler(RequestValidationError)
    async def validation_error(request: Request, exc: RequestValidationError) -> Response:
        return _error(request, ErrorCode.INVALID_REQUEST)

    @app.exception_handler(Exception)
    async def unexpected_error(request: Request, exc: Exception) -> Response:
        logger.error(
            "Unexpected analyzer error.",
            extra={"request_id": getattr(request.state, "request_id", None), "exception_type": type(exc).__name__},
        )
        return _error(request, ErrorCode.INTERNAL_ERROR)

    return app


def _error(request: Request, code: ErrorCode) -> Response:
    body = canonical_json(error_body(code, getattr(request.state, "request_id", None)))
    return Response(content=body, status_code=code.status, media_type="application/json")
