# syntax=docker/dockerfile:1
#
# CodeDNA frontend — Node images for Next.js 16.
#
# Build context: frontend/     (see frontend/.dockerignore)
# Extra context: "node" -> docker/node/ (development only)
#
# Targets:
#   development (default, docker-compose.yml): source bind-mounted; the
#     Next.js dev server; node_modules in a named volume so Linux-native
#     packages never mix with a macOS/Windows host's node_modules.
#   production (docker-compose.prod.yml, Phase 25): `next build` with the
#     standalone output; the runtime image holds only the traced server
#     files and static assets (no dev dependencies, no source, no npm), and
#     runs `node server.js` as an unprivileged user. No secret is needed at
#     build time and none is baked in: the server reads BACKEND_INTERNAL_URL
#     and FRONTEND_URL at runtime, and nothing is exposed to the browser
#     through NEXT_PUBLIC_* variables.

# Node 22 LTS (Next.js 16 requires Node >= 20.9).
FROM node:22-trixie-slim AS deps
ENV NEXT_TELEMETRY_DISABLED=1
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

FROM node:22-trixie-slim AS build
ENV NEXT_TELEMETRY_DISABLED=1 \
    NODE_ENV=production
WORKDIR /build
COPY --from=deps /build/node_modules ./node_modules
COPY . .
RUN npm run build \
    && rm -rf .next/cache

# ---------------------------------------------------------------------------
# production
# ---------------------------------------------------------------------------
FROM node:22-trixie-slim AS production

ENV NEXT_TELEMETRY_DISABLED=1 \
    NODE_ENV=production \
    HOSTNAME=0.0.0.0 \
    PORT=3000

# npm and corepack are not needed to run the server.
RUN rm -rf /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/corepack \
        /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack

WORKDIR /app
# Owned by root, read-only to the server's user.
COPY --from=build --chown=root:root /build/.next/standalone ./
COPY --from=build --chown=root:root /build/.next/static ./.next/static

# The image's unprivileged "node" user (uid 1000).
USER node
EXPOSE 3000
CMD ["node", "server.js"]

# ---------------------------------------------------------------------------
# development (default target)
# ---------------------------------------------------------------------------
FROM node:22-trixie-slim AS development

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
