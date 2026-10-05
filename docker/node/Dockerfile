# syntax=docker/dockerfile:1
#
# CodeDNA frontend — Node DEVELOPMENT image for Next.js 16.
#
# Build context: frontend/     (see frontend/.dockerignore)
# Extra context: "node" -> docker/node/
#
# Source is bind-mounted; node_modules lives in a named volume so Linux-native
# packages never mix with a macOS/Windows host's node_modules. A production
# image is a later phase (Phase 25).

# Node 22 LTS (Next.js 16 requires Node >= 20.9).
FROM node:22-trixie-slim

ENV NEXT_TELEMETRY_DISABLED=1

# Align the image's `node` user with the host UID/GID so files written to the
# bind mount (e.g. .next/) stay owned by the developer.
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN if [ "${HOST_GID}" != "1000" ]; then groupmod --non-unique --gid "${HOST_GID}" node; fi \
    && if [ "${HOST_UID}" != "1000" ]; then usermod --non-unique --uid "${HOST_UID}" --gid "${HOST_GID}" node; fi \
    && mkdir -p /app/node_modules \
    && chown -R node:node /app

COPY --from=node --chmod=0755 entrypoint.sh /usr/local/bin/codedna-entrypoint

WORKDIR /app
USER node

ENTRYPOINT ["codedna-entrypoint"]
CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0", "--port", "3000"]
