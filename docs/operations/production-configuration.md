# Production configuration

The environment contract for [`docker-compose.prod.yml`](../../docker-compose.prod.yml)
(Phase 25). The template is [`.env.production.example`](../../.env.production.example). It
keeps every secret empty, and `scripts/check_repo.py` enforces that. The
development contract is [`.env.example`](../../.env.example).

Configuration is injected at runtime only. No production value exists in Git,
an image, a Dockerfile, the Compose file, the frontend bundle or CI. Each
Laravel container validates its configuration at start
(`App\Support\ConfigurationValidator`) and refuses to start on any problem.
It never falls back to a default.

Kinds: **required** (Compose refuses to render without it) · **optional**
(safe default) · **fixed** (set by the Compose file; a deployment cannot change
it) · **development-only** (meaningful in `.env.example` only).

## Required values

| Variable | Kind | Notes |
|---|---|---|
| `APP_VERSION` | required | Release identifier: image tag and the version `/api/v1/health` reports |
| `CODEDNA_DOMAIN` | required | Public hostname, lowercase. Gives `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` and the GitHub callback. Nginx refuses other names |
| `TLS_CERTIFICATE_FILE`, `TLS_PRIVATE_KEY_FILE` | required | Host paths to the PEM chain and key ([TLS](production-deployment.md#6-obtain-the-tls-certificate)) |
| `APP_KEY` | required, secret | `base64:` + 32 random bytes. Never generated automatically: the entrypoint refuses to start without it |
| `DB_PASSWORD` | required, secret | PostgreSQL application password |
| `REDIS_PASSWORD` | required, secret | Redis `requirepass` |
| `SOURCE_STORAGE_ACCESS_KEY_ID`, `SOURCE_STORAGE_SECRET_ACCESS_KEY` | required, secret | Bucket-scoped application credentials |
| `MINIO_ROOT_USER`, `MINIO_ROOT_PASSWORD` | required, secret | Bundled MinIO administrator, used only by `minio-init` |
| `ANALYZER_HMAC_SECRET` | required, secret | ≥ 32 characters (64 hex recommended); shared by Laravel and the analyzer |

## Fixed values

Set in `docker-compose.prod.yml` and checked by `make prod-config`:

| Variable | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | Turns on every production check |
| `APP_DEBUG` | `false` | No stack traces, SQL, paths or internal URLs in responses |
| `SESSION_SECURE_COOKIE` | `true` | Cookies over HTTPS only |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `redis` | Shared state across containers |
| `TRUSTED_PROXIES` | `172.30.10.0/24` | Only Nginx, on the app network's fixed subnet, may set `X-Forwarded-*` |
| `LOG_CHANNEL` / `LOG_STDERR_FORMATTER` | `stderr` / `Monolog\Formatter\JsonFormatter` | Structured logs on stderr |
| `CHALLENGE_EVALUATOR_ISOLATION` | `gvisor` | Code is only submitted to an evaluator that attests gVisor |
| `EVALUATOR_PRODUCTION`, `EVALUATOR_ISOLATION` | `true`, `gvisor` | The evaluator refuses to start without gVisor |
| `BILLING_PROVIDER` | `none` | No payment provider is integrated; `fake` is refused at boot |
| `NODE_ENV` (frontend) | `production` | Production React build; the nonce CSP is active |

## Optional values

| Variable | Default | Notes |
|---|---|---|
| `LOG_LEVEL` | `info` | `debug` is refused in production |
| `HTTP_PORT`, `HTTPS_PORT`, `PUBLIC_BIND_ADDRESS` | `80`, `443`, `0.0.0.0` | Published Nginx ports |
| `CODEDNA_IMAGE_PREFIX` | `codedna` | Image name prefix (e.g. a private registry path) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` | bundled `postgres` | A managed database: set `DB_HOST` and `DB_SSLMODE` |
| `DB_SSLMODE` | `prefer` | `require`, `verify-ca` or `verify-full`: **required** for a `DB_HOST` that is not an internal service name (Phase 27) |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_SCHEME` | bundled `redis`, `6379`, plain TCP | A customer-run Redis: `REDIS_SCHEME=tls` is **required** for a host that is not an internal service name ([enterprise configuration](../enterprise/configuration-reference.md#customer-run-data-services)) |
| `REGISTRATION_MODE`, `REGISTRATION_ALLOWED_EMAIL_DOMAINS` | `open`, empty | `restricted` (only the listed email domains) or `closed` ([registration](../enterprise/configuration-reference.md#registration)) |
| `CODEDNA_LICENSE_FILE` | empty (Community edition) | Host path of a signed enterprise license, mounted as the `codedna_license` secret ([licensing](../enterprise/licensing.md)) |
| `DB_PERSISTENT` | `true` | One PostgreSQL connection kept per PHP-FPM worker (Phase 26: ×2 throughput). `false` connects per request, e.g. behind PgBouncer in transaction mode; see [database performance](../performance/database-performance.md#connections) |
| `ANALYZER_MAX_CONCURRENCY` | `2` | Analyses the analyzer runs at once. The **backend** reads the same variable to size its shared slot pool, so set it once in `.env`; 1–64. See [queue performance](../performance/queue-performance.md#analyzer-slots) |
| `ANALYZER_SLOT_WAIT_SECONDS` | `20` | How long a worker waits for an analyzer slot before handing the run to a delayed job (no attempt used); `ANALYZER_TIMEOUT_SECONDS` + this must stay below `ANALYSIS_JOB_TIMEOUT_SECONDS` |
| `REDIS_MAXMEMORY` | `384mb` | See [Redis](#redis) |
| `SESSION_LIFETIME` | `120` | Minutes, 5 to 1440 |
| `SESSION_SAME_SITE` | `lax` | `lax` or `strict` |
| `SESSION_DOMAIN` | empty | Empty = host-only cookie (recommended) |
| `CORS_ALLOWED_ORIGINS` | empty | Exact `https://` origins only; empty = same origin only |
| `SOURCE_STORAGE_*` (endpoint, region, bucket, path style, prefix) | bundled MinIO | See [object storage](#object-storage) |
| `ANALYZER_ALLOWED_SOURCE_HOSTS`, `ANALYZER_LOCAL_SOURCE_HOSTS` | `minio` | Hostnames pre-signed URLs may use |
| `ANALYZER_HMAC_SECRET_PREVIOUS` | empty | Only during an HMAC rotation |
| `GITHUB_APP_*` | empty (off) | All or nothing; the private key as PEM in `GITHUB_APP_PRIVATE_KEY` |
| `AI_*` | disabled | `AI_ENABLED=true` needs a pulled model. The default is the bundled local Ollama runtime (`--profile local-ai`, internal http). A remote endpoint needs https and `AI_ALLOW_REMOTE_ENDPOINT=true` ([local AI](local-ai.md)) |
| `CHALLENGE_ENABLED`, `CHALLENGE_EVALUATOR` | `true`, `spool` | `CHALLENGE_EVALUATOR=none` disables execution entirely |
| `EVALUATOR_RUNTIME` | `runsc` | Name of the registered gVisor runtime |
| Source, analyzer and challenge limits | as in development | Same ranges, validated at boot |

## Development-only values

`APP_ENV=local`, `APP_DEBUG=true`, the `*_HOST_PORT` variables, `HOST_UID` /
`HOST_GID`, `FRONTEND_WATCH_POLLING`, `BILLING_PROVIDER=fake`,
`CHALLENGE_EVALUATOR_ISOLATION=container`, `GITHUB_APP_PRIVATE_KEY_PATH` and
plain-http local test doubles exist only in `.env.example`. A deployed
environment refuses each of them.

## What production refuses at boot

Every environment other than `local` and `testing` counts as deployed
(`staging` included). Each item below stops the container with a message
naming the variable, never its value:

- `APP_KEY` missing; `APP_DEBUG=true`; `APP_URL` not https;
  `SESSION_SECURE_COOKIE` not true; no stateful domain;
- database host, name, user or password missing; an unknown `DB_SSLMODE`;
- Redis without a password (or a password in `REDIS_URL`);
- a session cookie that is not HttpOnly, `SameSite=none`, or a lifetime
  outside 5–1440 minutes;
- CORS: wildcards, patterns or anything but exact origins (everywhere);
  non-https origins (production);
- `TRUSTED_PROXIES` empty, a wildcard, `0.0.0.0/0` or a hostname (everywhere);
- `LOG_LEVEL=debug`;
- object storage without credentials or bucket; an `http://` endpoint
  other than an internal service name; credentials in the endpoint URL;
- `CHALLENGE_EVALUATOR=spool` without `CHALLENGE_EVALUATOR_ISOLATION=gvisor`;
- a missing or short analyzer HMAC secret; the fake AI or billing provider;
  insecure GitHub or AI URLs; the earlier timeout-chain and version checks;
- `ANALYZER_MAX_CONCURRENCY` outside 1–64, or an analyzer timeout plus slot
  wait that does not fit inside the analysis job timeout (everywhere);
- a `DB_HOST` or `REDIS_HOST` outside the private network without TLS;
- an invalid `REGISTRATION_MODE` or domain list, or a configured license file
  that cannot be read (everywhere; a license that does not verify is not an
  error, it grants nothing).

`ConfigurationValidatorTest` covers each rule.

## Evaluator sandbox

The evaluator executes untrusted code. Production requires
[gVisor](production-deployment.md#3-install-gvisor-for-the-evaluator):

1. The image's production target sets `EVALUATOR_PRODUCTION=true` and
   `EVALUATOR_ISOLATION=gvisor`. Production with any other isolation is a
   configuration error.
2. At start the evaluator **attests** its runtime from inside the
   container (gVisor's synthetic kernel identity). If the runtime is not
   gVisor it exits (`evaluator.refused`) before preparing the spool or
   writing a heartbeat, and removes any stale heartbeat. There is no
   fallback.
3. The heartbeat publishes the attested isolation. Laravel submits code only
   when the heartbeat is fresh and reports at least
   `CHALLENGE_EVALUATOR_ISOLATION`. It checks again right before each
   queued submission. Otherwise submissions answer
   `409 CHALLENGE_EVALUATION_UNAVAILABLE` and nothing is executed.

Details: [challenge evaluator](../architecture/challenge-evaluator.md#production-sandbox).

## Redis

One authenticated, persistent instance (AOF, `everysec`) on the internal
data network, or a customer-run Redis over TLS
(`docker/enterprise/compose.external-redis.yml`, Phase 27). Sessions and queues use database 0; the cache uses database 1
(`REDIS_CACHE_DB`). The memory ceiling is `REDIS_MAXMEMORY` with
`noeviction`: queued jobs, sessions and rate-limit counters are never
silently evicted. At the limit, writes fail and the failure is visible in
logs and health checks. Size it for the session and queue volume, and
alert at 80 %.

## Object storage

The bundled MinIO is private: never published, with no anonymous access, a
bucket-scoped application user and its own volume. To use an external
S3-compatible provider:

- set `SOURCE_STORAGE_ENDPOINT` (https), `SOURCE_STORAGE_REGION`,
  `SOURCE_STORAGE_BUCKET`, `SOURCE_STORAGE_USE_PATH_STYLE` and
  bucket-scoped credentials;
- set `ANALYZER_ALLOWED_SOURCE_HOSTS` to the provider's exact hostname and
  `ANALYZER_LOCAL_SOURCE_HOSTS` to empty (https and public addresses only);
- add `docker/enterprise/compose.external-storage.yml` to every compose
  command (Phase 27). It switches `minio` and `minio-init` off and gives the
  analyzer an egress network of its own (it has no internet route by
  default). Restrict that route at the firewall to the storage host; the
  analyzer's host allow-list and SSRF checks still apply.

The analyzer receives only pre-signed GET URLs valid for
`SOURCE_URL_TTL_SECONDS` (900 s by default) and never holds storage
credentials. The browser never receives a storage URL or credential.

## Logging

Laravel writes one JSON object per line to stderr (`LOG_STDERR_FORMATTER`).
Nginx writes JSON access lines without query strings, with invitation
tokens redacted. Every Laravel log record passes through
`App\Support\Logging\RedactSecrets`, which replaces values under secret-like
keys and recognizable secrets in messages (bearer tokens, URL credentials,
provider tokens, private keys, invitation tokens) with `[redacted]`. Code
still never logs secrets on purpose. The redaction is a backstop
([security baseline](security-baseline.md#logging)).

An empty `LOG_STDERR_FORMATTER` now means the default line format. Before
Phase 25 it broke logger creation.

## Health endpoints

| Endpoint | Kind | Checks | Exposes |
|---|---|---|---|
| `/nginx-health` | Nginx liveness | none | `ok` |
| `/up` | Laravel liveness | the application boots | status only |
| `/api/v1/health` | readiness | database and Redis | `ok`/`fail` per check, service, version |
| analyzer `/internal/v1/health` | liveness (internal network only) | process | status only |
| evaluator heartbeat | liveness and isolation | heartbeat age, `isolation=gvisor` | — |

No health response includes hostnames, error messages or configuration.
