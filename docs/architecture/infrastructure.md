# Infrastructure — Local Docker Development Environment

This document describes the Phase 02 development environment: services,
routing, networks, volumes, environment variables, and day-to-day operation.
Production deployment is a later phase (Phase 25). Nothing here is a
production configuration.

Related: [ADR-001](../decisions/ADR-001-stack.md) (versions),
[ADR-003](../decisions/ADR-003-storage.md) (storage),
[ADR-005](../decisions/ADR-005-service-communication.md) (analyzer isolation),
[ADR-006](../decisions/ADR-006-authentication.md) (single origin).

## Topology

```text
  Browser ── http://localhost  (127.0.0.1:80)
     │
     ▼
 ┌────────┐  /api/*  /sanctum/*  /up    ┌────────────────────────┐
 │ nginx  │ ──────────────────────────► │ backend                │
 │        │                             │ Laravel 13 · PHP-FPM   │
 │        │  everything else + HMR WS   └──┬──────┬──────┬───────┘
 │        │ ─────────┐                     │      │      │  HTTP (internal)
 └────────┘          ▼                     ▼      ▼      ▼
              ┌────────────┐          postgres  redis  analyzer (FastAPI)
              │ frontend   │                             │ pre-signed GET
              │ Next.js 16 │          backend (S3) ──► minio ◄──┘
              └────────────┘                             ▲
                                                 minio-init (one-shot)

  network codedna           : nginx, frontend, backend, postgres, redis, minio
  network codedna-internal  : backend, analyzer, minio, minio-init  (no internet)
```

The analyzer is **not** routed by Nginx and shares no network with Nginx or
the frontend. Only the backend calls it. The analyzer's only outbound
traffic is to MinIO, for pre-signed downloads
([ADR-005](../decisions/ADR-005-service-communication.md)).

## Services

| Service | Image / build | Purpose | Host port | Networks | Healthcheck |
|---|---|---|---|---|---|
| `nginx` | `nginx:1.28-alpine` | Single-origin router | `127.0.0.1:80` | codedna | `GET /nginx-health` |
| `frontend` | `docker/node/Dockerfile` → `codedna-frontend:dev` | Next.js 16 dev server ([frontend.md](frontend.md)) | — | codedna | HTTP `GET /` on :3000 |
| `backend` | `docker/php/Dockerfile` → `codedna-backend:dev` | Laravel 13 API on PHP-FPM 8.4 ([backend.md](backend.md)) | — | codedna, codedna-internal | Laravel `/up` over FastCGI (`codedna-healthcheck`) |
| `analyzer` | `docker/python/Dockerfile` → `codedna-analyzer:dev` | FastAPI; only `GET /internal/v1/health` | — | codedna-internal | `GET /internal/v1/health` |
| `postgres` | `postgres:16-alpine` | Primary database | `127.0.0.1:5432` | codedna | `pg_isready` |
| `redis` | `redis:7.4-alpine` | Cache, queues, sessions | `127.0.0.1:6379` | codedna | `redis-cli ping` |
| `minio` | `cgr.dev/chainguard/minio` (digest-pinned) | Local S3-compatible storage | `127.0.0.1:9000` (API), `127.0.0.1:9001` (console) | codedna, codedna-internal | `GET /minio/health/live` |
| `minio-init` | `cgr.dev/chainguard/minio-client` (digest-pinned) | One-shot: bucket and bucket-scoped app user | — | codedna-internal | exits 0 |

All host ports are bound to **127.0.0.1 only** and can be changed in `.env`
(`*_HOST_PORT`). Backend, frontend and analyzer publish no host ports.

Containers reach each other by **service name** (`postgres:5432`,
`redis:6379`, `minio:9000`, `analyzer:8000`, `backend:9000`,
`frontend:3000`), never by `localhost`.

## Single-origin routing (Nginx)

Configuration: `docker/nginx/conf.d/default.conf` and
`docker/nginx/snippets/laravel-fastcgi.conf`, mounted read-only.

| Path | Destination | Notes |
|---|---|---|
| `/api/*` | Laravel (`backend:9000`, FastCGI → `public/index.php`) | Unknown API routes return Laravel JSON 404s |
| `/sanctum/*` | Laravel | `GET /sanctum/csrf-cookie` (Sanctum SPA auth) |
| `/up` | Laravel | Liveness (no dependency checks); readiness is `GET /api/v1/health` |
| `/internal/*` | **404 at Nginx** | The internal analyzer API is never public |
| `/nginx-health` | Nginx | Nginx liveness |
| everything else | Next.js (`frontend:3000`) | Includes the hot-reload WebSocket (`/_next/hmr`) |

Other settings: `client_max_body_size 55m` (50 MiB archive limit plus
multipart overhead; matches PHP's `post_max_size`; a larger `/api/` body is
answered by Nginx with a JSON `413 PAYLOAD_TOO_LARGE` in the API's error
envelope), `server_tokens off`,
forwarded headers (`X-Forwarded-For`, `-Proto`, `-Host`), and upstream names
resolved per request through Docker DNS, so recreating a container never needs
an Nginx restart.

## Networks

| Network | Type | Members | Why |
|---|---|---|---|
| `codedna` | bridge | nginx, frontend, backend, postgres, redis, minio | Main development network; allows host port publishing |
| `codedna-internal` | bridge, `internal: true` | backend, analyzer, minio, minio-init | **No route to the internet.** Isolates the analyzer, which will process untrusted code |

`make verify` asserts the isolation: Nginx and the frontend cannot reach
the analyzer, and the analyzer cannot reach the internet.

## Volumes

| Volume (project `codedna`) | Mounted at | Contents |
|---|---|---|
| `codedna_postgres_data` | `postgres:/var/lib/postgresql/data` | Database |
| `codedna_redis_data` | `redis:/data` | Append-only file (queued jobs survive restarts) |
| `codedna_minio_data` | `minio:/data` | Object storage |
| `codedna_frontend_node_modules` | `frontend:/app/node_modules` | Linux-native npm packages, kept apart from the host |

Source code is bind-mounted: `./backend` → `/var/www/backend`,
`./frontend` → `/app`, `./analyzer` → `/app` (read-only).
`backend/vendor/` is created on the host by the backend container and is
git-ignored.

## Container hardening (development baseline)

- App containers run as **non-root**. Backend and frontend run as your host
  UID/GID (`HOST_UID`/`HOST_GID`), so files they write to bind mounts stay
  yours. The analyzer runs as a fixed UID 10001.
- The analyzer additionally has a read-only root filesystem, a 256 MiB
  `/tmp` tmpfs, all Linux capabilities dropped, `no-new-privileges`, and
  1 GiB memory, 2 CPU and 256 PID limits.
- No container is privileged. No service mounts the Docker socket.
- Interactive API docs (`/docs`, `/openapi.json`) are disabled in the
  analyzer.

## Environment variables

`.env` (created by `make setup`, git-ignored) is read by Docker Compose for
interpolation. Each container receives **only** the variables listed for it
in `docker-compose.yml`, never the whole file. For example, MinIO root
credentials reach only `minio` and `minio-init`.

| Variable | Required | Used by | Notes |
|---|---|---|---|
| `HOST_UID`, `HOST_GID` | generated | image builds | Your `id -u`/`id -g` (1000 when running as root) |
| `NGINX_HOST_PORT`, `POSTGRES_HOST_PORT`, `REDIS_HOST_PORT`, `MINIO_API_HOST_PORT`, `MINIO_CONSOLE_HOST_PORT` | defaults | compose | Host ports (127.0.0.1) |
| `APP_KEY` | **yes**, generated | backend | `base64:` + 32 random bytes |
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_VERSION`, `LOG_LEVEL`, `LOG_STDERR_FORMATTER` | defaults | backend | `APP_URL=http://localhost`; JSON logs via `LOG_STDERR_FORMATTER` |
| `DB_DATABASE`, `DB_USERNAME` | defaults | postgres, backend | |
| `DB_PASSWORD` | **yes**, generated | postgres, backend | |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE` | defaults | backend | Redis sessions, ADR-006 |
| `SANCTUM_STATEFUL_DOMAINS` | default `localhost,localhost:3000` | backend | Browser origins that get session auth |
| `TRUSTED_PROXIES` | default private ranges | backend | Proxies allowed to set `X-Forwarded-*` |
| `CORS_ALLOWED_ORIGINS` | default empty (CORS closed) | backend | Split-origin fallback only |
| `MINIO_ROOT_USER` | **yes** (default in template) | minio, minio-init | Admin only; never used by the app |
| `MINIO_ROOT_PASSWORD` | **yes**, generated | minio, minio-init | |
| `SOURCE_STORAGE_REGION`, `SOURCE_STORAGE_BUCKET` | defaults | backend, minio, minio-init | `us-east-1`, `codedna` locally |
| `SOURCE_STORAGE_ACCESS_KEY_ID`, `SOURCE_STORAGE_SECRET_ACCESS_KEY` | **yes**, generated | backend, minio-init | Bucket-scoped application user |
| `SOURCE_STORAGE_PREFIX` | default empty | backend | Optional key prefix inside the bucket (tests use `phpunit/`) |
| `SOURCE_MAX_ARCHIVE_BYTES`, `SOURCE_MAX_UNCOMPRESSED_BYTES`, `SOURCE_MAX_FILES`, `SOURCE_MAX_SINGLE_FILE_BYTES`, `SOURCE_MAX_PATH_LENGTH` | defaults (50 MiB, 200 MiB, 20000, 25 MiB, 512) | backend | Upload limits ([API reference](../api/README.md#upload-limits)); validated at boot |
| `ANALYZER_HMAC_SECRET` | generated | — | Used from Phase 08 |
| `FRONTEND_WATCH_POLLING` | default `false` | frontend | Polling file watcher fallback |
| `FRONTEND_URL` | default `http://localhost` | frontend (server only) | Origin presented to Sanctum for server-side session checks |

Fixed in `docker-compose.yml` (not configurable in `.env`, because they are
properties of the Docker network): `DB_HOST=postgres`, `REDIS_HOST=redis`,
`SOURCE_STORAGE_ENDPOINT=http://minio:9000`, `BACKEND_INTERNAL_URL=http://nginx` (frontend → API),
`ANALYZER_URL=http://analyzer:8000`, `MAIL_MAILER=log`, plus the
`pgsql`/`phpredis`/`redis` driver selections. The backend refuses to boot
with an invalid configuration (see [backend.md](backend.md#configuration-and-logging)).

Variables marked `[Phase NN]` in `.env.example` are documented but not
consumed yet. Compose stops with a clear `Set X in .env` error if a required
variable is missing.

**Why `SOURCE_STORAGE_*` and not `AWS_*` / `MINIO_*`:** the application talks
to MinIO and R2 through one provider-neutral configuration. Shell-level
`AWS_*` variables, which are common on developer machines, would silently
override `.env` during Compose interpolation and could also be picked up
implicitly by AWS SDKs.

## Day-to-day operation

```bash
make setup           # once: .env with random local secrets + build images
make up              # start everything, wait until healthy, run migrations
make migrate         # apply Laravel migrations to the development database
open http://localhost
make ps              # status and health
make logs            # follow all logs;  make logs s=backend  for one service
make shell-backend   # bash in the Laravel container (artisan, composer)
make shell-frontend  # bash in the Next.js container (npm)
make shell-analyzer  # bash in the analyzer container
make test            # analyzer pytest + backend PHPUnit (codedna_test DB) + frontend Vitest
make lint-backend    # Laravel Pint style check
make lint-frontend   # ESLint + TypeScript type check
make verify          # runtime smoke test (see below)
make down            # stop and remove containers; volumes are kept
make build           # rebuild images after Dockerfile or requirements changes
```

To delete **all local data** (database, Redis, MinIO, node_modules volume),
run `docker compose down -v`.

The first `make up` takes longer. The backend runs `composer install` and
the frontend runs `npm ci`, and both re-run automatically when
`composer.lock` or `package-lock.json` change.

### Databases and Redis allocation

| Purpose | PostgreSQL database | Redis DB |
|---|---|---|
| Development | `codedna` | 0 (sessions, queues), 1 (cache, rate limits) |
| Backend tests | `codedna_test` (created by `make test`) | 14, 15 (prefix `codedna-test-`) |

Tests never touch the development database. `phpunit.xml` pins the test
database, and the test bootstrap refuses any database whose name does not
end in `_test`.

### Hot reload

| App | Mechanism |
|---|---|
| Next.js | `next dev` with HMR over WebSocket through Nginx. Set `FRONTEND_WATCH_POLLING=true` if changes are missed. |
| Laravel | Source bind-mounted; OPcache revalidates every request |
| Analyzer | `uvicorn --reload` watching `/app/app` |

Analyzer dependency changes need `make build`, because packages are baked
into the image and the analyzer has no internet access at runtime.

## MinIO usage and local S3 configuration

- **Console:** <http://localhost:9001>. Log in with `MINIO_ROOT_USER` and
  `MINIO_ROOT_PASSWORD` from `.env`.
- **Bucket:** `codedna` (`SOURCE_STORAGE_BUCKET`). It is private, with no
  anonymous access.
- **Application user:** `SOURCE_STORAGE_ACCESS_KEY_ID`. Its policy only allows
  `s3:GetBucketLocation`, `s3:ListBucket`, `s3:GetObject`, `s3:PutObject` and
  `s3:DeleteObject` on that bucket. It cannot create buckets or administer
  MinIO.
- **Provisioning:** `docker/minio/init.sh`, run by `minio-init` on every
  `make up`. It is idempotent: it creates the bucket if missing, replaces the
  policy, upserts the user, and attaches the policy only if it isn't
  already attached.
- **Endpoint for containers:** `http://minio:9000`, path-style, region
  `us-east-1`. Pre-signed URLs are generated for host `minio`, so they are
  valid inside the Docker networks, where the analyzer uses them, but not
  from the host browser. That is intentional: browsers never download
  source archives.
- **From the host:** the API is at `http://127.0.0.1:9000`, for example with
  the `mc` or `aws` CLI using the application credentials.
- **Objects (Phase 07):** uploaded archives live at
  `projects/{project_id}/snapshots/{snapshot_id}/source.zip`. Tests write
  under `phpunit/<ulid>/…` and delete it again. To inspect locally:
  `mc ls --recursive app/codedna/projects/`.
- **Laravel's S3 client** is `league/flysystem-aws-s3-v3` (AWS SDK for PHP),
  configured as the `sources` disk; only the standard S3 API is used.

### MinIO image

Upstream MinIO no longer publishes freely pullable container images (the
`minio/minio` and `minio/mc` repositories reject anonymous pulls), and the
Bitnami images have been withdrawn. The environment therefore uses
**Chainguard's maintained builds of upstream MinIO**
(`cgr.dev/chainguard/minio`, `cgr.dev/chainguard/minio-client`, `-dev`
variants that include a shell for healthchecks and the init script). They
are **pinned by digest** for reproducibility. Chainguard's free tier
publishes only `latest` tags, so to update:

```bash
docker pull cgr.dev/chainguard/minio:latest-dev
docker image inspect cgr.dev/chainguard/minio:latest-dev --format '{{index .RepoDigests 0}}'
# put the new digest in docker-compose.yml, then: make up && make verify
```

Because only the standard S3 API is used, the local server can be swapped for
any S3-compatible alternative without application changes.

## Runtime verification (`make verify`)

`scripts/verify-infra.sh` checks the following against the running stack,
and prints no secrets:

1. All services are healthy and `minio-init` exited 0.
2. Routing: `/nginx-health`, `/` (Next.js), `/up` (Laravel), `/api/*`
   (Laravel JSON 404), `/internal/*` (404 at Nginx).
3. Networking: backend → analyzer and MinIO; Laravel → PostgreSQL
   (`artisan db:show`) and Redis (cache round-trip); Nginx and the frontend
   **cannot** reach the analyzer; the analyzer **cannot** reach the internet.
4. Frontend through Nginx: `/login` and `/register` return `200`, anonymous
   `/app` returns `307` to `/login`, no `X-Powered-By` header is sent, and
   `/app` renders for the probe user's session (the Next.js server checks
   the session with Laravel).
5. Backend API through Nginx: `/api/v1/health` reports database and Redis
   `ok`. Then a real browser-style Sanctum flow: CSRF cookie, `419` without
   `X-XSRF-TOKEN` (also with `Sec-Fetch-Site: cross-site`), register, `/me` with the session, logout, `/me` → `401`,
   login. It also checks that the session cookie is HttpOnly. The probe user
   is deleted afterwards.
6. Storage, using the application credentials: upload; bucket creation is
   denied; a pre-signed GET URL is generated and **downloaded by the
   analyzer**; the unsigned URL is rejected; the object is deleted.
7. Profile (Phase 06): `GET`/`PATCH /api/v1/profile`, and `419` for
   cross-site profile and password mutations without the CSRF token.
8. Projects and uploads through Nginx (Phase 07), with a separate probe
   account: create a project; upload a real ZIP (`201`, version 1); the
   object exists in MinIO under the expected key; a cross-site upload
   without the CSRF token is `419`; a non-ZIP is `422
   SOURCE_ARCHIVE_INVALID`; a 56 MiB body is `413 PAYLOAD_TOO_LARGE` as
   JSON from Nginx; archive, then upload is `409 PROJECT_ARCHIVED`. The
   probe's objects and rows are deleted.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Set DB_PASSWORD in .env` (or similar) | Run `make setup`, or fill the variable in `.env` |
| Port 80 (or 5432/6379/9000/9001) already in use | Change the matching `*_HOST_PORT` in `.env`. For Nginx also set `APP_URL` (e.g. `http://localhost:8080`), then `make up` |
| `backend`/`frontend` not healthy on first start | Dependencies are still installing: `make logs s=backend` / `s=frontend` |
| Backend fails with `Invalid CodeDNA configuration` | The message lists each problem (e.g. missing `APP_KEY`, non-Redis driver). Fix `.env`, then `make up` |
| `relation "users" does not exist` | Migrations not applied: `make migrate` |
| Permission denied writing `backend/storage` or `frontend/.next` | `HOST_UID`/`HOST_GID` in `.env` must match `id -u`/`id -g`; then `make build && make up` |
| Frontend changes not picked up | Set `FRONTEND_WATCH_POLLING=true` in `.env`, then `make up` |
| Analyzer dependency missing after editing requirements | `make build && make up` |
| `minio-init` failed | `make logs s=minio-init`. Check that `MINIO_ROOT_PASSWORD` and `SOURCE_STORAGE_SECRET_ACCESS_KEY` are at least 8 characters |
| Changed `MINIO_ROOT_*` or `DB_PASSWORD` after first start | The existing volume keeps the old credentials. Restore the old values, or reset data with `docker compose down -v` |
| Stale Next.js dependencies after switching branches | `docker compose down`, `docker volume rm codedna_frontend_node_modules`, then `make up` |

## Not included yet (later phases)

Queue worker and scheduler containers (Phase 10), TLS and production images
(Phase 25), and the R2 bucket and credentials (Phase 25; the application
side needs only `SOURCE_STORAGE_*`).
