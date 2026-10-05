# backend/ — Laravel API

**Status: Phase 03 foundation.** Laravel 13.34 on PHP 8.4 provides:

- Sanctum SPA (cookie and session) authentication: `POST /api/v1/auth/register`,
  `/login`, `/logout`, plus `GET /api/v1/me`
- `GET /api/v1/health` (PostgreSQL and Redis readiness) and `GET /up`
  (liveness)
- Versioned routes (`/api/v1`), `{"data": …}` responses and a standard
  `{"error": {code, message, request_id, details}}` envelope
- Redis-backed sessions, cache and queue, rate limiting, request IDs, and
  configuration validation at boot

There is **no business domain yet**: no projects, repositories or analyses.

Configuration comes **only from environment variables** set by
`docker-compose.yml`. There is deliberately no `backend/.env`.

```bash
make up              # also runs migrations
make test            # PHPUnit against the dedicated codedna_test database
make lint-backend    # Laravel Pint
make shell-backend   # bash in the container (php artisan …)
```

- Architecture: [docs/architecture/backend.md](../docs/architecture/backend.md)
- API reference: [docs/api/README.md](../docs/api/README.md)
- Analyzer contract (client side, Phase 10): [docs/api/internal-analyzer-contract.md](../docs/api/internal-analyzer-contract.md)
- Decisions: [ADR-001](../docs/decisions/ADR-001-stack.md), [ADR-005](../docs/decisions/ADR-005-service-communication.md), [ADR-006](../docs/decisions/ADR-006-authentication.md)
