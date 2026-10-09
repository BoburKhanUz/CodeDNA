# Self-hosted installation

Installing CodeDNA on your own infrastructure (Phase 27). The procedure is
the [production deployment runbook](../operations/production-deployment.md)
plus the self-hosted decisions below. Follow that runbook step by step; this
page says where to make each decision and what to add.

## Before you start

**You need:**

- one Linux host with:
  - Docker Engine and the Compose plugin (v2.20 or newer, for optional
    dependencies);
  - the gVisor runtime for coding challenges, or `CHALLENGE_EVALUATOR=none`
    to switch code execution off;
- a DNS name and a TLS certificate for it;
- the sizing for your user count ([sizing](#sizing)).

**You decide:**

| Decision | Options | Where |
|---|---|---|
| PostgreSQL | bundled container, or yours | [Data services](#data-services) |
| Redis | bundled container, or yours | [Data services](#data-services) |
| Object storage | bundled MinIO, or any S3-compatible service | [Data services](#data-services) |
| Who may sign up | open, restricted to your email domains, or closed | [configuration reference](configuration-reference.md#registration) |
| Edition | Community (no license), or Enterprise (a license from the issuer) | [licensing](licensing.md) |
| Optional integrations | GitHub App, an AI provider | [outbound network](#outbound-network-requirements) |

## Procedure

Follow the [runbook](../operations/production-deployment.md#procedure),
with these additions:

1. **Environment file** (runbook step 7). Start from
   `.env.production.example`. It documents every variable, including the
   Phase 27 ones:
   - `REGISTRATION_MODE` and `REGISTRATION_ALLOWED_EMAIL_DOMAINS`;
   - `CODEDNA_LICENSE_FILE`;
   - `REDIS_HOST`, `REDIS_PORT` and `REDIS_SCHEME`.

   The full list is in the
   [configuration reference](configuration-reference.md).
2. **Compose files.** With customer-run data services, add the matching
   overlays to **every** `docker compose` command:

   ```bash
   COMPOSE="docker compose -f docker-compose.prod.yml \
     -f docker/enterprise/compose.external-postgres.yml \
     -f docker/enterprise/compose.external-redis.yml \
     -f docker/enterprise/compose.external-storage.yml \
     --env-file /etc/codedna/production.env"
   ```

   Use only the overlays you need. `make prod-config` checks the production
   file alone and with all overlays.
3. **License** (optional). Install it before the first start
   ([licensing](licensing.md#install)).
4. **Preflight** (new, after the data services run and before the
   migrations, runbook step 13):

   ```bash
   $COMPOSE run --rm --no-deps backend php artisan codedna:preflight
   ```

   It reports, without printing any value:
   - that the configuration validated;
   - whether PostgreSQL, Redis (both databases) and object storage answer
     with the configured credentials;
   - the pending migrations;
   - the license status;
   - the registration mode.

   It writes nothing. Fix every `FAIL` before you continue
   ([troubleshooting](troubleshooting.md#preflight)).
5. **Migrations, start, health and edge checks:** runbook steps 14–17,
   unchanged. `codedna:preflight` should now report `migrations: up to
   date`.
6. **First account.** Sign up through the web interface. With
   `REGISTRATION_MODE=closed`, open registration only long enough to create
   the first accounts, then close it and restart the backend containers.
   Teams are created and members invited from the web interface
   ([teams](../teams/teams-architecture.md)).

## Data services

### Bundled (default)

PostgreSQL 16, Redis 7 and MinIO run as private containers on internal
networks, each with its own volume. This is the simplest setup. Back them
up as described in [backup and restore](../operations/backup-and-restore.md).

### Customer-run PostgreSQL

- **Version and database:** PostgreSQL 16 (15 or newer works but is not
  tested), with an empty database and an owner role for CodeDNA. The
  migrations create everything else.
- **TLS:** `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
  `DB_PASSWORD`, and `DB_SSLMODE=verify-full` (or `require`). The backend
  refuses to boot with a non-internal `DB_HOST` and a weaker mode.
- **Overlay:** `compose.external-postgres.yml` switches the bundled
  container off. It gives the scheduler and the migration job the egress
  route the backend and queue already have.
- **Connections:** size `max_connections` as described in
  [database performance](../performance/database-performance.md#connections).
  Persistent connections are on (`DB_PERSISTENT=true`). Behind a pooler,
  use PgBouncer in **session** mode; transaction mode breaks the locks the
  jobs rely on.

### Customer-run Redis

- **Version and settings:** Redis 7, with a password,
  `maxmemory-policy noeviction`, and persistence (AOF or RDB). It holds
  queues, sessions, locks and rate limits; an evicting Redis loses jobs.
- **TLS:** `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` and
  `REDIS_SCHEME=tls`. The backend refuses a non-internal `REDIS_HOST`
  without TLS.
- **Certificate:** the server certificate is verified against the system
  trust store of the backend image. A private CA is not supported without
  rebuilding the image.
- **Overlay:** `compose.external-redis.yml` switches the bundled container
  off and gives the scheduler and migration job their route.

### Customer-run object storage

Any S3-compatible service that supports pre-signed GET URLs: AWS S3,
Cloudflare R2, MinIO, Ceph RGW and so on.

1. **Bucket and credentials:** create a private bucket with credentials
   scoped to it.
2. **Variables:** set
   - `SOURCE_STORAGE_ENDPOINT` (https), `SOURCE_STORAGE_REGION`,
     `SOURCE_STORAGE_BUCKET` and `SOURCE_STORAGE_USE_PATH_STYLE`;
   - the credentials;
   - `ANALYZER_ALLOWED_SOURCE_HOSTS=<the endpoint's exact host>`;
   - `ANALYZER_LOCAL_SOURCE_HOSTS=` (empty).
3. **Overlay:** `compose.external-storage.yml` switches MinIO off and gives
   the analyzer a dedicated egress network, because it downloads sources
   through pre-signed URLs. Restrict that route at your firewall to the
   storage endpoint. The analyzer's own hostname allow-list and SSRF checks
   still apply.

## Outbound network requirements

At **runtime**, CodeDNA needs no internet access with the bundled data
services and no optional integration:

| Destination | Needed by | When |
|---|---|---|
| Your PostgreSQL, Redis, object storage | backend, queue, scheduler, migrate (analyzer: storage only) | only when customer-run |
| `api.github.com`, `github.com`, `codeload.github.com` (443) | backend, queue | only with the GitHub App configured |
| Nothing for AI with the bundled local runtime (`ollama`, private network); a remote `AI_BASE_URL` (443) | ai-worker, backend (health check) | only with `AI_ENABLED=true` and `AI_ALLOW_REMOTE_ENDPOINT=true` ([local AI](../operations/local-ai.md#trust-boundary)) |
| `registry.ollama.ai` and its download hosts (443) | ollama | only while you pull a model |

**Never contacted:**

- no license server or telemetry;
- no update check;
- no analytics: Next.js telemetry is disabled in the image;
- no mail server: this release sends no email (invitations are links an
  admin shares).

The evaluator has no network at all.

**Installation time** is different. Building the images downloads base
images (Docker Hub, `cgr.dev`) and packages (Debian, PyPI, npm, Packagist).

## Air-gapped and restricted networks

Running without internet access is possible. Building without it is not.

To install on a host that cannot reach the internet:

1. **On a connected machine:** build the images (`--profile migrate build`)
   and save them, together with the third-party images (`postgres`,
   `redis`, `minio`, `minio-client`), using `docker save`.
2. **Transfer and load:** move the archive, then `docker load` it on the
   target host.
3. **Configure:** set `CODEDNA_IMAGE_PREFIX`/`APP_VERSION` to the loaded
   tags, and use `up --no-build`.
4. **Integrations:** leave GitHub and AI unset.

Limitations, stated plainly:

- **Not tested end to end.** This procedure has not been tested in a fully
  air-gapped environment. The runtime makes no outbound call in this
  configuration, but your CA certificates and time synchronization (NTP,
  needed for license validity) are your environment's responsibility.
- **Coding challenges need gVisor.** The `runsc` runtime must be installed
  on the host from your own mirror.
- **No offline AI provider.** AI interpretation is unavailable until a
  local provider exists (planned for Phase 29).

## Sizing

Start from the measured
[resource recommendations](../performance/scaling-guide.md#resource-recommendations):

| Profile | Users | Host |
|---|---|---|
| Small | ≤ 1,000 active | one host, 8 CPU / 16 GB (the compose defaults) |
| Medium | ≤ 10,000 active | larger PostgreSQL and more workers: see the scaling guide |

The web tier and queue workers scale horizontally. The analyzer and the
evaluator run as single instances
([scaling guide](../performance/scaling-guide.md#what-scales-how)).

## Operational responsibilities

| The installation (CodeDNA) | You (the operator) |
|---|---|
| Refuses unsafe configuration at boot | Hosts, OS patches, Docker, gVisor |
| Isolates services on internal networks | Firewall rules for the egress routes you enable |
| Keeps sessions, cookies and source storage private | TLS certificates and their renewal |
| Writes structured, redacted logs to stdout | Log collection, retention and alerting |
| Exposes health and readiness endpoints | Monitoring and on-call |
| Verifies the license offline | Keeping the license file current; NTP |
| — | Backups and restore drills ([backup and restore](../operations/backup-and-restore.md)) |
| — | Upgrades ([upgrade and rollback](upgrade-and-rollback.md)) |
