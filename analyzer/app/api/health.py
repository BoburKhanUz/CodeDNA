"""Liveness endpoint for container health checks.

Unauthenticated by design (see docs/api/internal-analyzer-contract.md): it
returns only non-sensitive status and version information.
"""

from fastapi import APIRouter

from app import __version__

router = APIRouter()


@router.get("/internal/v1/health")
def health() -> dict[str, object]:
    return {"status": "ok", "versions": {"analyzer": __version__}}
