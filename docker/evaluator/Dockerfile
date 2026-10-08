# syntax=docker/dockerfile:1
#
# CodeDNA challenge evaluator (Phase 16) — runs submitted code in a sandbox.
#
# Build context: evaluator/   (see evaluator/.dockerignore)
#
# Standard library only: nothing is installed, at build time or at runtime.
# The code is baked into the image (no bind mount), owned by root and not
# readable by the sandbox users, except the runner they execute. At runtime
# the container has no network, a read-only root filesystem and only the
# SETUID, SETGID and KILL capabilities; the supervisor runs as root inside it
# to drop each job to an unprivileged slot user.
#
# Targets:
#   development (default, docker-compose.yml): includes the test suite
#     (`make test-evaluator` runs it inside the container).
#   production (docker-compose.prod.yml, Phase 25): no tests, and production
#     mode on: the service refuses to start unless it attests a gVisor
#     sandbox (evaluator/evaluator/isolation.py). There is no fallback.

FROM python:3.11-slim-trixie AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# Spool group (shared with the Laravel queue worker through a volume) and one
# unprivileged user per sandbox slot. Slot users are in no other group.
RUN groupadd --gid 10500 codedna-spool \
    && for i in 0 1 2 3; do \
         uid=$((10002 + i)); \
         groupadd --gid "$uid" "sandbox$i" \
         && useradd --uid "$uid" --gid "$uid" --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "sandbox$i"; \
       done \
    && mkdir -p /sandbox /spool

COPY --chown=root:root evaluator /opt/evaluator/evaluator

WORKDIR /opt/evaluator

# ---------------------------------------------------------------------------
# production
# ---------------------------------------------------------------------------
FROM base AS production

# Supervisor code: root only. The runner: readable by everyone (it holds
# nothing secret). pip is removed: nothing can be installed at runtime.
RUN chmod -R go-rwx /opt/evaluator \
    && chmod 0711 /opt/evaluator /opt/evaluator/evaluator \
    && chmod 0755 /opt/evaluator/evaluator/runner \
    && chmod 0644 /opt/evaluator/evaluator/runner/*.py \
    && pip uninstall -y pip setuptools wheel && rm -rf /root/.cache

# Fail closed: production requires an attested gVisor runtime.
ENV EVALUATOR_PRODUCTION=true \
    EVALUATOR_ISOLATION=gvisor

# The supervisor must be root to drop each job to its slot user (setuid);
# compose limits it to the SETUID, SETGID and KILL capabilities, no network
# and a read-only root filesystem. Submitted code never runs as root.
# hadolint ignore=DL3002
USER root
CMD ["python3", "-m", "evaluator.service"]

# ---------------------------------------------------------------------------
# development (default target)
# ---------------------------------------------------------------------------
FROM base AS development

COPY --chown=root:root tests /opt/evaluator/tests

# Supervisor code and tests: root only. The runner: readable by everyone
# (it holds nothing secret).
RUN chmod -R go-rwx /opt/evaluator \
    && chmod 0711 /opt/evaluator /opt/evaluator/evaluator \
    && chmod 0755 /opt/evaluator/evaluator/runner \
    && chmod 0644 /opt/evaluator/evaluator/runner/*.py

# hadolint ignore=DL3002
USER root
CMD ["python3", "-m", "evaluator.service"]
