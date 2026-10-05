# backend/ — Laravel API

**Status: Phase 02 bootstrap.** This is the stock Laravel 13 skeleton
(`laravel/laravel` 13.10, framework 13.34), so the container has something
real to boot. It has **no CodeDNA features**: no routes, models, migrations
or configuration have been written for the product yet. The **Phase 03**
backend foundation will configure the application: API versioning, Pest,
Pint, Larastan, test database, and removing the unused front-end tooling.

What runs today (via Docker, see
[infrastructure.md](../docs/architecture/infrastructure.md)):

- PHP-FPM 8.4 behind Nginx at `http://localhost`. `/api/*`, `/sanctum/*`
  and `/up` are routed here.
- Configuration comes **only from environment variables** set by
  `docker-compose.yml`. There is deliberately no `backend/.env`.
- Connected to PostgreSQL (`pgsql`), Redis (`phpredis`: cache, queue,
  sessions) and the internal analyzer network.
- The skeleton's default migrations, `User` model and example tests are
  untouched and **not run**. The domain model is Phase 05.

Responsibilities: authentication, developer profiles, projects, repositories,
source snapshots, analysis orchestration (queue jobs), result persistence,
DNA snapshots, authorization and business rules. Laravel **never** parses or
analyzes source code.

```bash
make shell-backend         # bash in the container
php artisan about          # inside the container
```

- Architecture: [docs/architecture/backend.md](../docs/architecture/backend.md)
- Public API conventions: [docs/api/README.md](../docs/api/README.md)
- Analyzer contract (client side): [docs/api/internal-analyzer-contract.md](../docs/api/internal-analyzer-contract.md)
- Decisions: [ADR-001](../docs/decisions/ADR-001-stack.md), [ADR-005](../docs/decisions/ADR-005-service-communication.md), [ADR-006](../docs/decisions/ADR-006-authentication.md)
