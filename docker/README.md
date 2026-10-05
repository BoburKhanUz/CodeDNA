# docker/ — Container build contexts

**Status: placeholders.** Dockerfiles and configuration are created in
**Phase 02** (Docker infrastructure). Until then only `docker-compose.yml`
at the repository root exists, running PostgreSQL 16 and Redis 7.

| Directory | Phase 02 content |
|---|---|
| `nginx/` | Single-origin router: `/api/*`, `/sanctum/*` → Laravel; everything else → Next.js ([ADR-006](../docs/decisions/ADR-006-authentication.md)) |
| `php/` | PHP-FPM image for the Laravel API, queue worker and scheduler |
| `node/` | Node image for Next.js |
| `python/` | Analyzer image: non-root, read-only root filesystem, limited temp directory ([analyzer.md](../docs/architecture/analyzer.md#source-intake-and-safety)) |

The analyzer container will be attached only to a private network and will
publish no host port ([ADR-005](../docs/decisions/ADR-005-service-communication.md)).
