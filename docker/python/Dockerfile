# syntax=docker/dockerfile:1
#
# CodeDNA analyzer — Python DEVELOPMENT image (FastAPI).
#
# Build context: analyzer/     (see analyzer/.dockerignore)
#
# Dependencies are baked into the image at build time from hash-locked
# requirement files: at runtime the analyzer runs on an internal-only network
# with no internet access and a read-only root filesystem, so it cannot
# install anything. Rebuild after changing requirements (`make build`).
# Source is bind-mounted read-only at /app.

FROM python:3.11-slim-trixie

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

COPY requirements.txt requirements-dev.txt /opt/codedna/

# Fixed unprivileged user; the analyzer never needs to write to its source.
RUN pip install --require-hashes -r /opt/codedna/requirements.txt -r /opt/codedna/requirements-dev.txt \
    && useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin analyzer

WORKDIR /app
USER analyzer

EXPOSE 8000
# The application is built by a factory, which validates the configuration
# from the environment (the process refuses to start without a valid HMAC
# secret and source allow-list).
CMD ["uvicorn", "app.main:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000", "--reload", "--reload-dir", "/app/app"]
