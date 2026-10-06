"""Liveness/readiness endpoint for container health checks.

Unauthenticated by design (contract section 2): it returns only the status,
versions and non-sensitive limits. No paths, hosts, URLs or secrets.
"""

from fastapi import APIRouter, Request

from app.config import Settings
from app.metrics.aggregate import METRICS_VERSION
from app.versions import ANALYZER_VERSION, CONTRACT_VERSION, IR_VERSION

router = APIRouter()


@router.get("/internal/v1/health")
def health(request: Request) -> dict[str, object]:
    settings: Settings = request.app.state.settings
    return {
        "status": "ok",
        "versions": {"analyzer": ANALYZER_VERSION, "contract": CONTRACT_VERSION, "ir": IR_VERSION, "metrics": METRICS_VERSION},
        "limits": settings.limits(),
    }
