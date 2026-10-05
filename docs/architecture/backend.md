# Backend Architecture (Laravel)

Laravel is the product and business backend. **Status: Phase 03
foundation.** It provides authentication, the versioned API skeleton,
standard responses and errors, health checks, and Redis-backed sessions,
cache and queues. No business domain exists yet.

Related: [ADR-001](../decisions/ADR-001-stack.md),
[ADR-005](../decisions/ADR-005-service-communication.md),
[ADR-006](../decisions/ADR-006-authentication.md),
[API reference](../api/README.md), [Data flow](data-flow.md),
[Infrastructure](infrastructure.md).

## Responsibilities

Laravel owns users and authentication. In later phases it also owns
profiles, projects, repositories and provider integrations, source
snapshots, analysis orchestration and status, result persistence, DNA
snapshots, competencies, learning data, organizations, teams and billing.
It also owns authorization, rate limiting and every business rule.

**Laravel never parses or analyzes source code.** It validates and stores
uploads and hands them to the analyzer (ADR-005).

## Versions

Laravel 13.34 and PHP 8.4. `composer.json` requires `php ^8.4` and pins
`config.platform.php` to `8.4.26`, the runtime image's version, so
dependencies resolve for the real runtime. Laravel Sanctum is 4.3.
Tests run on PHPUnit 12. See ADR-001 for why Pest and Larastan are not in
use yet.

## Request lifecycle

```text
Nginx ──FastCGI──► PHP-FPM ──► global middleware
                                 AssignRequestId   (X-Request-ID, log context)
                                 TrustProxies      (X-Forwarded-* from TRUSTED_PROXIES)
                                 HandleCors        (closed unless CORS_ALLOWED_ORIGINS)
                                 ValidatePostSize, TrimStrings, ConvertEmptyStringsToNull
                             ──► `api` group
                                 EnsureFrontendRequestsAreStateful (Sanctum: cookies,
                                   session, CSRF for first-party browser requests)
                                 throttle:api, SubstituteBindings
                             ──► route middleware (auth:sanctum, RequireSession, throttle:login …)
                             ──► Controller ─► FormRequest (validation)
                                            ─► Action (application operation)
                                            ─► API Resource (serialization)
             any exception ──► ApiExceptionRenderer ─► {"error": {...}}
```

## Code organization

```text
backend/
├── app/
│   ├── Actions/Auth/              RegisterUser, AuthenticateUser, StartUserSession, LogoutUser
│   ├── Exceptions/                ApiException (client-facing, carries an ErrorCode),
│   │                              InvalidConfigurationException
│   ├── Http/
│   │   ├── Controllers/Api/V1/    HealthController, MeController, Auth/{Register,Login,Logout}Controller
│   │   ├── Errors/                ErrorCode (vocabulary), ApiExceptionRenderer
│   │   ├── Middleware/            AssignRequestId, RequireSession
│   │   ├── Requests/Auth/         RegisterRequest, LoginRequest
│   │   └── Resources/             UserResource
│   ├── Models/User.php
│   ├── Policies/UserPolicy.php
│   ├── Providers/AppServiceProvider.php   config validation, rate limiters, password rules, strict models
│   ├── Services/SystemHealth.php          database/Redis readiness checks
│   └── Support/ConfigurationValidator.php
├── bootstrap/app.php              routing (api prefix), middleware, exception rendering
├── config/codedna.php             CodeDNA settings (service, version, proxies, rate limits)
├── routes/api.php                 mounts /api/v1 → routes/api_v1.php
└── tests/{Unit,Feature/{Auth,Api,Infrastructure}}
```

### Conventions

- **Controllers are thin and invokable.** Each one validates (FormRequest),
  authorizes (Policy via the `Gate` contract), calls one Action, and returns
  a Resource.
- **Actions** are small, `final readonly` classes with one `handle()`
  method, one per application operation. Dependencies are injected through
  the constructor. They are HTTP-agnostic except that session-based auth
  actions receive the `Session` contract. There is no catch-all
  `AuthService`.
- **No repository layer.** Eloquent is the persistence abstraction. Add a
  class only when it carries real behavior.
- **Validation lives in FormRequests.** Inputs are normalized in
  `prepareForValidation` (e.g. lowercase emails).
- **Serialization uses API Resources** with explicit field lists. Models are
  never returned directly.
- **Configuration is read via `config()` or the `Config` contract.** `env()`
  appears only in `config/*.php`.
- New `app/` files use `declare(strict_types=1)`, typed properties and
  return types. Style is Laravel Pint (Laravel preset), checked with
  `make lint-backend`.
- `#[SensitiveParameter]` marks password parameters, so they are redacted
  from stack traces.

## API versioning and responses

Routes are prefixed `/api/v1` (`apiPrefix: 'api'` plus a `v1` group in
`routes/api.php`). Success responses wrap the resource in `data`. Errors use
the `{"error": {code, message, request_id, details?}}` envelope produced by
`ApiExceptionRenderer` for every request under `api/*` or expecting JSON.
The full contract is in [docs/api/README.md](../api/README.md).

The renderer maps framework exceptions to the `ErrorCode` vocabulary, for
example validation → `VALIDATION_FAILED`, authentication →
`AUTHENTICATION_REQUIRED`, policy denial → `FORBIDDEN`, missing route or
model → `RESOURCE_NOT_FOUND`, CSRF → `CSRF_TOKEN_MISMATCH`, throttling →
`RATE_LIMITED`, anything else → `INTERNAL_ERROR`. It never includes exception
messages or traces, regardless of `APP_DEBUG`. Unexpected exceptions are
still reported to the log. `Retry-After` and other HTTP exception headers
are preserved. Guests on protected API routes get a JSON `401`, never a
redirect.

## Authentication and authorization

- **Sanctum SPA (stateful) authentication** on the `web` session guard
  (ADR-006). `statefulApi()` gives first-party browser requests (Origin or
  Referer in `SANCTUM_STATEFUL_DOMAINS`) cookies, a session and CSRF
  validation. Other requests are stateless. `auth:sanctum` protects routes.
- **Register and login** regenerate the session ID. **Logout** logs out,
  invalidates the session and rotates the CSRF token. `RequireSession`
  returns `400` when register, login or logout is called without a session.
- **Passwords** are hashed with bcrypt through the model's `hashed` cast.
  The policy is 8–72 characters (`Password::defaults()`). Emails are stored
  lowercase and unique.
- **Personal access tokens** (for future non-browser clients) need no
  redesign: the `personal_access_tokens` table exists (with ULID morphs) so
  Sanctum can reject unknown bearer tokens cleanly. Issuing tokens, and the
  `HasApiTokens` trait, come with that feature.
- **Email verification** is not active. `email_verified_at` exists. To
  enable it, implement `MustVerifyEmail`, configure a mailer and add the
  verification routes. `Registered` is already dispatched on sign-up.
  Mail goes to the log for now (`MAIL_MAILER=log`).
- **Authorization** uses policies, resolved by Laravel's naming convention.
  `UserPolicy::view` allows a user to view only their own account, and `/me`
  checks it through the `Gate` contract. Organization, team and project
  authorization will be added as new policies on top of the same guard,
  without changing authentication.

## Database

- PostgreSQL 16. The config default is `pgsql` (`DB_CONNECTION`), and boot
  fails if it is anything else.
- Primary keys are ULIDs (`HasUlids`), never exposed sequences.
- Phase 03 tables: `users`, `personal_access_tokens`, `failed_jobs`, and
  `migrations`. Laravel's default `sessions`, `cache`, `jobs`,
  `job_batches` and `password_reset_tokens` tables are not created, because
  those features run on Redis or don't exist yet.
- `Model::shouldBeStrict()` outside production makes lazy loading, unknown
  attributes and silently discarded attributes throw. Destructive DB
  commands are prohibited in production.
- Migrations: `make migrate`, which `make up` also runs.

### Transactions

Use a transaction (`DB::transaction`) when an action writes **more than one
row that must change together**, for example a project and its first
repository, or an analysis run plus its DNA snapshot (data-flow.md). Don't
add transactions mechanically: registration performs a single insert and
uses none. The Redis queue connection has `after_commit = true`, so jobs
dispatched inside a transaction are pushed only after it commits.
`RegisterUser` turns a unique-constraint race on `email` into a normal
validation error.

### Planned tables (later phases)

| Table | Purpose | Phase |
|---|---|---|
| `profiles` | Developer profile data (1:1 with users) | 06 |
| `projects` | User-owned project containers | 07 |
| `repositories` | Source origins (`provider`: `upload` first) | 07 |
| `source_snapshots` | Immutable archives: object key, SHA-256, size, commit SHA, origin | 07 |
| `analyses` | A request to analyze a snapshot | 10 |
| `analysis_runs` | Executions: status, attempts, versions, request ID, failure info, timestamps | 10 |
| `analysis_metrics` | Metrics per run (overall and per language; JSONB plus query columns) | 10 |
| `analysis_features` | Feature vector per run (JSONB) | 10 |
| `analysis_findings` | Secret and parse findings per run (locations only) | 10 |
| `dna_snapshots` | Immutable DNA result per completed run | 11 |

`repository_files` and a materialized `developer_dna` table are not planned
for the MVP. Developer DNA is derived from `dna_snapshots`
([ADR-004 §7](../decisions/ADR-004-dna-scoring.md#7-developer-level-dna-proposal)).

## Redis: cache, sessions, queues

| Use | Driver | Redis connection / DB (development) |
|---|---|---|
| Cache | `redis` store | `cache` connection, DB 1 |
| Sessions | `redis` | `default` connection, DB 0 (`codedna-database-` prefix) |
| Queues | `redis` connection, queue `default` | `default` connection, DB 0 |
| Rate limiter | default cache store | DB 1 |
| Tests | — | DBs 14 and 15, prefix `codedna-test-` |

Outside the test suite, boot fails unless sessions, cache and queues use
Redis (`ConfigurationValidator`).

**Queue foundation.** The Redis queue and the `failed_jobs` table exist,
and a test proves a job is pushed to Redis, executed by `queue:work` and
removed. No application jobs and no worker container exist yet. The worker
service, the dedicated `analysis` queue and `RunAnalysisJob` (tries 3,
timeout 330 s, backoff 30 s/120 s, `retry_after` 360 s) come in Phase 10
(ADR-005).

## Configuration and logging

- **Fail fast.** `AppServiceProvider` runs `ConfigurationValidator` at boot,
  for HTTP and artisan alike, and throws `InvalidConfigurationException`
  listing every problem. It always checks `APP_KEY` and PostgreSQL, checks
  for Redis drivers outside tests, and in production also requires
  `APP_DEBUG=false`, an `https://` `APP_URL`, secure cookies and Sanctum
  stateful domains.
- **Trusted proxies** come from `TRUSTED_PROXIES` (private ranges by
  default, because only Nginx reaches PHP-FPM).
- **Logging** writes to stderr (`LOG_CHANNEL=stderr`). Every line includes
  the `request_id` (Laravel Context). For JSON logs in production, set
  `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`.
- **Never logged:** passwords (redacted parameters; Laravel never flashes
  password fields), session cookies, CSRF tokens, secrets, source code.
  Health-check failures log only the exception class.
- **Version leakage:** PHP's `expose_php=Off` and Nginx's `server_tokens
  off` mean no `X-Powered-By` header or server version is sent. Error
  bodies never contain traces.

## Testing

```bash
make test           # analyzer pytest + backend PHPUnit (in containers)
make lint-backend   # Laravel Pint --test
```

- **Dedicated test database:** `codedna_test`, created idempotently by
  `scripts/ensure-test-database.sh` (`make test` runs it).
  `phpunit.xml` forces it with `<server … force="true">`, because the
  container's development variables would otherwise win. `tests/TestCase.php`
  refuses to run unless the database name ends in `_test`, and the check
  runs before any migration.
- Feature tests use `RefreshDatabase` (PostgreSQL, transactions per test),
  in-memory cache and sessions, and sync queues by default. Infrastructure
  and session-lifecycle tests use real Redis (DBs 14 and 15).
- `SessionLifecycleTest` discards all in-memory session and guard state
  between requests, so authentication, session regeneration and logout
  invalidation are proven through the Redis-stored session the cookie
  points to.
- CSRF enforcement is bypassed by Laravel during PHPUnit runs. It is verified
  end to end through Nginx by `make verify`.
- Run PHPUnit directly (`vendor/bin/phpunit`, as `make test` does).
  `php artisan test` also works, but its printer lists an `@`-suppressed
  phpdotenv warning about the intentionally absent `backend/.env` for every
  test. PHPUnit itself reports no warnings.
