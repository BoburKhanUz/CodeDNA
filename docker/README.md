# docker/ — Container configuration

Used by the root `docker-compose.yml` (development) and
`docker-compose.prod.yml` (production, Phase 25). Full documentation:
[docs/architecture/infrastructure.md](../docs/architecture/infrastructure.md) and
[docs/operations/production-deployment.md](../docs/operations/production-deployment.md).

Each application Dockerfile has two targets: `development` (the default,
source bind-mounted) and `production` (code baked in, owned by root,
read-only, no development dependencies, unprivileged user).

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
| `minio/Dockerfile` | The same script baked into the pinned client image (production `minio-init`) |
| `evaluator/Dockerfile` | Challenge evaluator; the production target enforces gVisor |
| `php/conf.d/production.ini`, `php/conf.d/fpm-production.conf` | Production PHP and FPM settings (no displayed errors, immutable OPcache) |
| `php/production-entrypoint.sh` | Builds Laravel's caches from the runtime environment; refuses to start without `APP_KEY` or with an invalid configuration |
| `nginx/production/` | Production TLS edge: config, snippets, template, fail-fast start check, image |
