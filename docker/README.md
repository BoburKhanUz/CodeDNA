# docker/ — Development container configuration

Used by the root `docker-compose.yml`. Full documentation:
[docs/architecture/infrastructure.md](../docs/architecture/infrastructure.md).

| Path | Purpose |
|---|---|
| `php/Dockerfile` | Backend image: PHP 8.4-FPM, pdo_pgsql, intl, pcntl, phpredis, Composer. Build context `backend/`. |
| `php/conf.d/` | PHP ini (upload limits, OPcache revalidation) and FPM pool overrides |
| `php/entrypoint.sh` | Runs `composer install` when `composer.lock` changes, then PHP-FPM |
| `php/healthcheck.sh` | Calls Laravel's `/up` over FastCGI |
| `node/Dockerfile` | Frontend image: Node 22 LTS for the Next.js 16 dev server. Build context `frontend/`. |
| `node/entrypoint.sh` | Runs `npm ci` when `package-lock.json` changes, then `next dev` |
| `python/Dockerfile` | Analyzer image: Python 3.11 with hash-locked FastAPI/Uvicorn. Build context `analyzer/`. |
| `nginx/conf.d/default.conf` | Single-origin router (mounted read-only) |
| `nginx/snippets/laravel-fastcgi.conf` | FastCGI hand-off to Laravel's front controller |
| `minio/init.sh` | Idempotent bucket and bucket-scoped app user provisioning (`minio-init`) |

These are **development** images: source is bind-mounted and nothing is
optimized for production. Production images come in Phase 25.
