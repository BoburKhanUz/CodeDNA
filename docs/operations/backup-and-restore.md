# Backup and restore

What to back up in a [production deployment](production-deployment.md), how,
and how to prove a backup restores. Commands assume
`DC="docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env"`.

## What holds state

| Data | Where | Back up | Notes |
|---|---|---|---|
| PostgreSQL (all business data) | volume `postgres_data` | **yes**, logical dumps (and volume snapshots if available) | The source of truth |
| Source archives | MinIO volume `minio_data`, or the external bucket | **yes** | Referenced by `source_snapshots.storage_key`. Immutable once written |
| Redis | volume `redis_data` | no (optional) | Sessions, cache, rate limits and queued jobs. Losing it logs users out and drops queued jobs. Stale-run sweepers mark interrupted analyses failed, and users can retry |
| `APP_KEY` and the other secrets | the organization's secret manager | **yes, separately** | Without the same `APP_KEY`, encrypted columns (GitHub tokens) are unreadable |
| Challenge spool | volume `challenge-spool` | no | Transient requests and results |
| TLS certificate and key | host files | as per the certificate process | Re-issuable |

## PostgreSQL

Daily logical dump (custom format, compressed), copied off the host:

```bash
stamp=$(date -u +%Y%m%dT%H%M%SZ)
$DC exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --no-owner' \
  > "/var/backups/codedna/db-$stamp.dump"
sha256sum "/var/backups/codedna/db-$stamp.dump" > "/var/backups/codedna/db-$stamp.dump.sha256"
```

Encrypt dumps before they leave the host (e.g. `age` or `gpg` to a key
held outside the host). Store them in a different failure domain from the
host. Keep 7 daily, 4 weekly and 6 monthly dumps, or as the organization's
retention rules require.

For a point-in-time recovery objective tighter than a day, use the
database host's or provider's WAL archiving or snapshots. A managed
PostgreSQL service provides this.

## Object storage

Mirror the bucket to a second location daily. Objects are write-once, so an
incremental mirror is enough:

```bash
# from a host with the MinIO client, using a read-only key for the source
mc mirror --overwrite=false codedna-prod/<bucket> backup-store/<bucket>-mirror
```

For the bundled MinIO, a volume-level backup of `minio_data` taken while
`minio` is stopped (or from a filesystem snapshot) also works. An external
provider's versioning or replication can replace the mirror.

## Before every deployment

1. Take a PostgreSQL dump as above and verify its checksum.
2. Record `$DC run --rm migrate php artisan migrate:status`.
3. Only then run the [migrations](production-deployment.md#14-run-the-migrations-explicitly).

## Restore

Restore into a stopped application, never under live traffic:

```bash
$DC stop nginx frontend backend queue scheduler
$DC exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" --if-exists "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
$DC exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --exit-on-error' \
  < /var/backups/codedna/db-<stamp>.dump
$DC up -d --wait
```

Then:

1. Restore objects referenced by the dump if storage was lost. Mirror back
   from the backup store.
2. Run the same images as at backup time (the [release record](production-deployment.md#19-record-the-release)).
   Restoring an older schema under newer code needs the
   [rollback procedure](rollback.md).
3. Check `/api/v1/health`, sign in, and open a project with an analysis.
4. Clear stale sessions if Redis was kept from a different point in time:
   `$DC exec redis redis-cli FLUSHDB`. This logs everyone out.

## Restore drill

At least quarterly, restore the latest dump into a scratch PostgreSQL
container and check it:

```bash
docker run -d --name codedna-restore-drill -e POSTGRES_PASSWORD=drill postgres:16-alpine
docker exec -i codedna-restore-drill sh -c 'until pg_isready -U postgres; do sleep 1; done; createdb -U postgres drill'
docker exec -i codedna-restore-drill pg_restore -U postgres -d drill --no-owner --exit-on-error < db-<stamp>.dump
docker exec codedna-restore-drill psql -U postgres -d drill -c 'select count(*) from users; select count(*) from projects;'
docker rm -f codedna-restore-drill
```

Record the date, the dump used, the row counts and the time taken. A backup
that has never been restored is not a backup.
