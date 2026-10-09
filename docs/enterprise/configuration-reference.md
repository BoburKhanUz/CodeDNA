# Configuration reference (self-hosted)

The settings a self-hosted installation decides (Phase 27).

Every other production variable is documented in
[production configuration](../operations/production-configuration.md), and
every variable appears, without values, in `.env.production.example`. The
backend validates all of them when any container starts
(`ConfigurationValidator`). A problem stops the container with a message
naming the variable, never its value.

## Registration

| Variable | Default | Values |
|---|---|---|
| `REGISTRATION_MODE` | `open` | `open`, `restricted` or `closed` |
| `REGISTRATION_ALLOWED_EMAIL_DOMAINS` | empty | comma-separated domains, e.g. `example.com,example.org` |

- **`open`:** anyone who can reach the installation may create an account.
  This was the only behavior before Phase 27.
- **`restricted`:** only addresses in the listed domains. The match is the
  exact domain after `@`, case-insensitive: `dev@example.com` matches
  `example.com`, but `dev@eng.example.com` does not. List subdomains
  explicitly. Other addresses get `422 VALIDATION_FAILED` on `email`.
- **`closed`:** no new accounts (`403 REGISTRATION_CLOSED`, before anything
  is validated). Existing accounts, sessions and invitations to existing
  accounts keep working.

**Enforcement.** The server enforces the mode on `POST
/api/v1/auth/register`, the only way to create an account. The sign-up page
shows the server's answer.

**Validation.** The backend refuses to start with:

- an unknown mode;
- `restricted` without domains;
- a malformed domain;
- domains set while the mode is not `restricted`, which would silently do
  nothing.

**Edition.** Registration control is available in every edition.

## Enterprise license

| Variable | Default | Meaning |
|---|---|---|
| `CODEDNA_LICENSE_FILE` | empty (`/dev/null`) | Path **on the host** to the signed license file. It is mounted read-only as the `codedna_license` secret at `/run/secrets/codedna_license` (`CODEDNA_LICENSE_PATH`, fixed). |

- **No license:** an empty or absent license is the Community edition.
- **Unreadable:** a configured path that does not exist or cannot be read
  stops the containers at boot.
- **Does not verify:** a file that is readable but does not verify does not
  stop them. The installation runs as the Community edition and reports the
  status ([licensing](licensing.md#statuses)).

**Trusted keys** are not configuration: they ship in the release
(`backend/config/license.php`). No environment variable can add one, and
`make prod-config` fails if any license-related variable other than the
path is set.

## Customer-run data services

| Variable | Default | Customer-run value |
|---|---|---|
| `DB_HOST` | `postgres` | your database host |
| `DB_SSLMODE` | `prefer` | `verify-full` (or `require`/`verify-ca`): **required** for any host that is not an internal service name |
| `REDIS_HOST` | `redis` | your Redis host |
| `REDIS_PORT` | `6379` | its port |
| `REDIS_SCHEME` | empty (plain TCP) | `tls`: **required** for any host that is not an internal service name |
| `SOURCE_STORAGE_ENDPOINT` | `http://minio:9000` | `https://…` (plain `http` only for an internal service name) |
| `ANALYZER_ALLOWED_SOURCE_HOSTS` | `minio` | the storage endpoint's exact host |
| `ANALYZER_LOCAL_SOURCE_HOSTS` | `minio` | empty |

An **internal service name** is a single label without dots (`postgres`,
`redis`, `minio`). Any name with a dot and any IP address counts as outside
the private network and must use TLS.

Each customer-run service has a compose overlay in `docker/enterprise/`
([installation](self-hosted-installation.md#data-services)):

- `compose.external-postgres.yml`;
- `compose.external-redis.yml`;
- `compose.external-storage.yml`.

## Settings that are deliberately not configurable

| Setting | Why |
|---|---|
| Plans, prices, features and quotas | Server-owned code, versioned ([entitlements](../billing/entitlements-and-quotas.md)) |
| Trusted license keys | An operator-supplied key would let anyone sign a license |
| `APP_ENV`, `APP_DEBUG`, secure cookies, trusted proxies, JSON logs | Fixed in `docker-compose.prod.yml` |
| The evaluator's isolation (`gvisor`) | Untrusted code never runs without the sandbox; `CHALLENGE_EVALUATOR=none` switches execution off instead |
| A payment provider | None is integrated (`BILLING_PROVIDER=none`) |
| An installation administrator | There is none: operators configure the host, organization owners and admins manage their organizations |

## Checking a configuration

```bash
make prod-config                                            # the compose files, with and without the overlays (no secrets needed)
docker compose ... run --rm --no-deps backend php artisan codedna:preflight   # this installation's dependencies
docker compose ... run --rm --no-deps backend php artisan codedna:license     # the license
```
