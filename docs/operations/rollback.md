# Rollback

How to return a [production deployment](production-deployment.md) to the
previous release. Commands assume
`DC="docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env"`.

## Principles

- **Roll the code back, not the data.** Images are immutable and tagged by
  `APP_VERSION`, so the previous release is one tag away.
- **Never blindly run `migrate:rollback` in production.** A `down()` method
  can drop columns or tables that hold data written since the deployment,
  and several tables are append-only by design (ledgers, audit logs,
  snapshots). Data written after the migration would be lost.
- Migrations are written to be **expand-then-contract**: a release adds
  columns and tables that the previous release ignores. In the normal case
  the previous code runs unchanged on the newer schema.

## Decide

| Situation | Action |
|---|---|
| The new release misbehaves; its migrations only added things | [Code rollback](#code-rollback) (no schema change) |
| A migration failed half-way | PostgreSQL ran it in a transaction and rolled it back. Fix forward or code-rollback |
| The previous code cannot run on the new schema (a breaking change slipped through) | [Restore rollback](#restore-rollback) |
| Data was corrupted by the new release | Stop writes, assess, then a targeted repair or a [restore rollback](#restore-rollback) |

## Code rollback

1. Find the previous release in the release record (`APP_VERSION`, commit).
2. Point the environment file at it, then restart the application services:

   ```bash
   sed -i 's/^APP_VERSION=.*/APP_VERSION=<previous>/' /etc/codedna/production.env
   $DC up -d --wait nginx frontend backend queue scheduler analyzer evaluator
   ```

3. Check `/api/v1/health` (it reports the running version), sign in, and
   look at failed jobs: `$DC exec queue php artisan queue:failed`.
4. Leave the newer schema in place. Remove its additions only in a later,
   reviewed release (contract phase), never as part of an incident.

The queue worker finishes its current job before it is replaced. Jobs
queued by the newer release run on the previous code. Job payloads are kept
compatible across adjacent releases. A job class that does not exist in the
previous release fails and stays in `failed_jobs`, from where it can be
retried after the fix.

## Restore rollback

Use this only when the previous code cannot run on the current schema. It
discards every write since the backup.

1. Announce downtime and stop the application:
   `$DC stop nginx frontend backend queue scheduler`.
2. Take a dump of the current state anyway, for forensics and selective
   recovery ([backup](backup-and-restore.md#postgresql)).
3. Restore the pre-deployment dump from
   [before every deployment](backup-and-restore.md#before-every-deployment).
4. Set `APP_VERSION` to the previous release and start:
   `$DC up -d --wait`.
5. Verify as in [step 16](production-deployment.md#16-check-health), and
   reconcile what was lost from the forensic dump.

## A migration's down()

`down()` methods exist for development and for tests. Running one in
production needs:

- a reviewed plan;
- a fresh backup;
- proof (on a restored copy) that it loses no data written since the
  migration.

Run it with `$DC run --rm migrate php artisan migrate:rollback --step=1 --force`
only after that review.

## After a rollback

Record what happened, which release and why. Add a regression test for the
cause, and fix forward in a new release.
