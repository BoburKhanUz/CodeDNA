# syntax=docker/dockerfile:1
#
# CodeDNA analyzer — Python images (FastAPI, Tree-sitter).
#
# Build context: analyzer/     (see analyzer/.dockerignore)
#
# Dependencies are baked into the image at build time from hash-locked
# requirement files: at runtime the analyzer runs on an internal-only network
# with no internet access and a read-only root filesystem, so it cannot
# install anything. Rebuild after changing requirements (`make build`).
#
# Targets:
#   development (default, docker-compose.yml): runtime and development
#     dependencies; source bind-mounted read-only at /app; auto-reload.
#   production (docker-compose.prod.yml, Phase 25): runtime dependencies
#     only, application code baked in (owned by root, read-only), no tests,
#     no reload. Same base image and Tree-sitter wheels as development, so
#     parsing behaves identically.

FROM python:3.11-slim-trixie AS runtime

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

COPY requirements.txt /opt/codedna/requirements.txt

# Fixed unprivileged user; the analyzer never needs to write to its source.
RUN pip install --require-hashes -r /opt/codedna/requirements.txt \
    && useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin analyzer

# ---------------------------------------------------------------------------
# production
# ---------------------------------------------------------------------------
FROM runtime AS production

# pip is not needed at runtime: nothing can be installed into the image.
RUN pip uninstall -y pip setuptools wheel && rm -rf /root/.cache

COPY --chown=root:root app /app/app
RUN chmod -R a+rX,go-w /app

WORKDIR /app
USER analyzer

EXPOSE 8000
# One process; concurrency is bounded inside the application
# (ANALYZER_MAX_CONCURRENCY). No --proxy-headers: only the signed internal
# client reaches it, and no X-Forwarded-* header is trusted.
CMD ["uvicorn", "app.main:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000", "--no-server-header", "--timeout-graceful-shutdown", "30"]

# ---------------------------------------------------------------------------
# development (default target)
# ---------------------------------------------------------------------------
FROM runtime AS development

COPY requirements-dev.txt /opt/codedna/requirements-dev.txt
RUN pip install --require-hashes -r /opt/codedna/requirements.txt -r /opt/codedna/requirements-dev.txt

WORKDIR /app
USER analyzer

EXPOSE 8000
# The application is built by a factory, which validates the configuration
# from the environment (the process refuses to start without a valid HMAC
# secret and source allow-list).
CMD ["uvicorn", "app.main:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000", "--reload", "--reload-dir", "/app/app"]
