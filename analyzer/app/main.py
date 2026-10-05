"""FastAPI application entry point (internal-only service)."""

from fastapi import FastAPI

from app import __version__
from app.api import health

# Interactive docs and the OpenAPI document are disabled: this service is
# internal-only and its contract is documented in docs/api/.
app = FastAPI(
    title="CodeDNA Analyzer",
    version=__version__,
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
)

app.include_router(health.router)
