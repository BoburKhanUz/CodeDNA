# Upgrade and rollback (self-hosted)

How to move a self-hosted installation between releases. The mechanics are
in two runbooks:

- [production deployment: updating](../operations/production-deployment.md#updating);
- [rollback](../operations/rollback.md).

This page adds the self-hosted checks and the compatibility promises.

## Compatibility promises

- **Migrations are additive.** They never drop or rewrite data a previous
  release needs. Index changes are built `CONCURRENTLY` where tables are
  large. An upgrade can therefore be rolled back by redeploying the previous
  images without restoring the database, unless a release's notes say
  otherwise ([rollback](../operations/rollback.md#principles)).
- **Persisted results are immutable and versioned.** DNA, competencies,
  skill gaps and growth are stored with the versions that produced them. An
  upgrade never recomputes or rewrites them.
- **The API is stable within `v1`.** Changes are additive
  ([API versioning](../api/README.md#versioning)).
- **Licenses carry versions.** A license format or schema a release does not
  understand is reported as `UNSUPPORTED_VERSION` and grants nothing. Check
  the release notes before upgrading across a license format change.

## Upgrade procedure

1. **Read the release notes.** Look for new required variables, a removed
   license key, or a migration that cannot be rolled back.
2. **Back up.** Take a database dump and an object storage copy
   ([backup and restore](../operations/backup-and-restore.md#before-every-deployment)).
3. **Get the new release** and build (or load) its images: runbook steps
   4 and 10.
4. **Validate the configuration:**

   ```bash
   make prod-config
   $COMPOSE run --rm --no-deps backend php artisan codedna:preflight
   ```

   `codedna:preflight` with the **new** images reports pending migrations
   and the license status under the new release, before anything changes.
5. **Migrate:** `$COMPOSE --profile migrate run --rm migrate`.
6. **Start:** `$COMPOSE up -d`. Then repeat the health and edge checks
   (runbook steps 16–17) and `codedna:preflight`, which should now show
   `up to date`.

`$COMPOSE` is your compose command with its overlays
([installation](self-hosted-installation.md#procedure)).

## Upgrading to Phase 27

- **No migration and no data change.** Phase 27 adds no table and changes
  no data. Edition and license are read at runtime.
- **New, optional variables:**
  - `REGISTRATION_MODE`, default `open`, the previous behavior;
  - `REGISTRATION_ALLOWED_EMAIL_DOMAINS`;
  - `CODEDNA_LICENSE_FILE`, default none;
  - `REDIS_HOST`, `REDIS_PORT` and `REDIS_SCHEME`, defaulting to the
    bundled Redis.
- **Stricter TLS (action may be required).** A `DB_HOST` that is not an
  internal service name now requires `DB_SSLMODE=require`, `verify-ca` or
  `verify-full`. Installations already following the managed-database
  guidance (`verify-full`) are unaffected. An installation that reached an
  external database without TLS will refuse to start until it enables TLS.
  That is intended: credentials and source metadata must not cross a
  network in clear text.
- **Compose v2.20 or newer** is required. The production file marks the
  bundled data services as optional dependencies (`required: false`).

## Rollback

Follow [rollback](../operations/rollback.md). For licenses:

- **Rolling back to a release without Phase 27** ignores the license. Teams
  return to their billing account's plan and seats, and no data is lost.
- **Rolling back to a release whose keyring lacks your license's key** makes
  the license `UNKNOWN_KEY`, with the same effect.
- **Removing a bad license** (`CODEDNA_LICENSE_FILE=` and recreate the
  containers) is always safe. Teams keep everything except the extra plan
  allowance and seats. New members cannot join while the team is over the
  account's seat limit, but no one is removed.
