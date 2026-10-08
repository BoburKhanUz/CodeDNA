# Database performance

PostgreSQL 16 and Redis: the query, N+1, index, connection and transaction
audits of Phase 26, the indexes behind every hot path, and maintenance
guidance.

## Query audit

Every API endpoint was run through
`make benchmark-api BENCH_API_ARGS=--show-queries` on
the medium data. `EXPLAIN (ANALYZE, BUFFERS)` was checked for each query
over 1 ms.

| Finding | Endpoint | Fix |
|---|---|---|
| Sequential scan + sort of whole DNA and skill-gap snapshot tables to find each project's latest | team analytics | Per-project `LATERAL … LIMIT 1` probes on the `(project_id, created_at, id)` indexes inside `MATERIALIZED` CTEs |
| `COUNT(*)` over the organization's whole audit log, then `OFFSET` | audit log | Keyset pagination (cursor only) |
| Sort of the project's whole history by run completion | history, history point | Partial index `analysis_runs_project_history_idx` and an index-prefix bound on neighbour lookups |
| "Newest assessment" scanned every succeeded analysis run on the platform, filtered after the join | growth overview | The run's project stated in the query, so the history index applies |
| One counter query per quota | billing, organization billing | One `IN (…)` query |
| Billing account loaded three times | organization billing | Passed along once loaded |
| Organization and membership loaded twice (form request + controller authorization) | every team-project read | Request-scoped ALLOW memo (`ViewDecisions`) |

### N+1 audit

No list endpoint issues queries per item. Layers and relations are loaded
with one `IN (…)` query per layer and page. `QueryBudgetTest` pins this:

- 33 endpoints are measured;
- for each one, the query count must not change when the data triples;
- the count must stay ≤ 14.

## Indexes

Phase 26 added one index:

```sql
CREATE INDEX CONCURRENTLY analysis_runs_project_history_idx
    ON analysis_runs (project_id, completed_at DESC, id DESC)
    WHERE status = 'SUCCEEDED';
```

It orders the project history and growth reads. It is partial, so queued,
running and failed runs do not bloat it: 8.8 MB at 94,500 runs.

The migration is non-transactional (`$withinTransaction = false`) and uses
`CONCURRENTLY IF NOT EXISTS`, so deploying it does not block writes to
`analysis_runs`. If a concurrent build is interrupted, PostgreSQL leaves an
`INVALID` index. Drop it and re-run the migration:

```sql
SELECT indexrelid::regclass FROM pg_index WHERE NOT indisvalid;
DROP INDEX CONCURRENTLY analysis_runs_project_history_idx;
```

Existing indexes that keyset and latest-snapshot reads rely on (asserted by
`IndexesTest`):

- `organization_audit_events (organization_id, created_at, id)`;
- `{dna,competency,skill_gap}_snapshots (project_id, created_at)`;
- `growth_snapshots (project_id, assessed_at)`;
- `analysis_runs (project_id, created_at)`;
- `billing_usage_events (user_id, created_at)`.

**Unused or duplicate indexes:** none were removed. Every index serves a
foreign key, a uniqueness or idempotency constraint, or a measured read.
The audit log's `(actor_user_id, created_at)` index (117 MB at medium)
backs the `restrictOnDelete` foreign key to `users`. Without it, every user
deletion would scan the whole audit log. Keep it.

## Sizes at medium scale

The database totals 2.5 GB.

| Table | Rows | Total | Of which indexes |
|---|---|---|---|
| organization_audit_events | 1.2M | 538 MB | 303 MB |
| growth_observations | 986k | 427 MB | 200 MB |
| competency_snapshots | 94k | 288 MB | 40 MB |
| dna_snapshots | 94k | 271 MB | 41 MB |
| skill_gap_results | 378k | 244 MB | 70 MB |
| growth_snapshots | 94k | 222 MB | 38 MB |
| skill_gap_snapshots | 94k | 201 MB | 54 MB |
| analysis_runs | 94k | 66 MB | 35 MB |

Per analysis, the derived layers cost about 15 KB in total. Per audit
event, the cost is about 450 B. These figures are measured; the projections
below are extrapolated:

- 1M analyses: ~15 GB of derived snapshots;
- 10M audit events: ~4.5 GB.

## Connections

PHP-FPM workers each hold at most one connection. Production enables
**persistent connections** (`DB_PERSISTENT=true` in
`docker-compose.prod.yml`). Each FPM worker keeps its connection across
requests, which saves connection setup and SCRAM authentication on every
request: throughput ×2, p50 −45%
([load testing](load-testing.md#persistent-connections)).

- **Safety.** On reuse, PDO's pgsql driver rolls back any transaction a
  request left open (verified: a transaction left open by one request was
  not visible to the next). Laravel sets no session-level state per
  request. The application's PostgreSQL session settings are the same for
  every request.
- **Sizing.** Peak connections = Σ FPM `pm.max_children` over web nodes +
  queue workers + scheduler + 3 (migrations, health checks, psql).
  Phase 25's production pool is 16 children per node. With 2 web nodes and
  4 workers, that is 16×2 + 4 + 1 + 3 = 40, under the default
  `max_connections` of 100. Past ~80, put PgBouncer in **session** mode in
  front (transaction mode breaks the advisory locks and `SELECT … FOR
  UPDATE` sequences the jobs use), or lower `pm.max_children`.
- **Connection storms.** A restart of every FPM worker reconnects at once.
  At the sizes above, that takes a few hundred milliseconds of
  authentication. It needs no special handling.

## Transactions

Audited every `DB::transaction` and lock:

- **Jobs** (analysis, AI assessment, roadmap, evaluation, GitHub import).
  Each job claims its run in a short transaction (`lockForUpdate`, status
  transition). It makes the external call with no transaction open, then
  records the outcome in a second short transaction. A worker that dies
  mid-call leaves a claim. The scheduled stale-run commands
  (`FailStaleAnalyses`, `FailStaleAssessments`, …) fail it. No row lock
  is held across network I/O.
- **Uploads and imports**: the archive is stored first. The snapshot row
  is created in a short transaction, and the stored object is deleted if
  that transaction fails.
- **Quotas**: consuming a quota locks only its own counter row
  (`lockForUpdate`) inside the transaction that creates the counted thing.
  That is why concurrent creates never exceed it
  ([load testing](load-testing.md#final-run), scenario F). The read-only
  quota summary takes no locks.
- **Organization changes** (membership, roles, transfers, billing) lock the
  organization row first, in a consistent order, so they serialize per
  organization without deadlocks.

## Maintenance

Autovacuum defaults suit the write pattern: mostly inserts, few updates or
deletes. Watch:

```sql
-- Dead tuples and the last (auto)vacuum per table
SELECT relname, n_live_tup, n_dead_tup, last_autovacuum, last_autoanalyze
FROM pg_stat_user_tables ORDER BY n_dead_tup DESC LIMIT 10;

-- Cache hit ratio (aim for > 99% in steady production)
SELECT round(100.0 * sum(blks_hit) / nullif(sum(blks_hit) + sum(blks_read), 0), 1)
FROM pg_stat_database WHERE datname = current_database();

-- Slowest statements (needs pg_stat_statements)
SELECT calls, round(mean_exec_time::numeric, 1) AS mean_ms, left(query, 120)
FROM pg_stat_statements ORDER BY total_exec_time DESC LIMIT 20;

-- Index usage: indexes never scanned since the last stats reset
SELECT relname, indexrelname, idx_scan, pg_size_pretty(pg_relation_size(indexrelid))
FROM pg_stat_user_indexes ORDER BY idx_scan, pg_relation_size(indexrelid) DESC LIMIT 20;
```

- **`analysis_runs`** is updated on every state change: queued, running,
  then succeeded or failed. At more than ~1,000 analyses per hour, set
  `ALTER TABLE analysis_runs SET (autovacuum_vacuum_scale_factor = 0.05)`
  so dead tuples are cleaned before they bloat the active-run indexes.
- **Audit events** are insert-only. On PostgreSQL 13+, autovacuum's
  insert threshold keeps the visibility map current, so index-only scans
  stay index-only.
- **Memory.** The development container measured a cache hit ratio of
  79.5% with the default `shared_buffers` (128 MB) against 2.5 GB of data.
  In production, set `shared_buffers` to ~25% of the database host's RAM
  and `effective_cache_size` to ~70%, so the hot working set fits. The hot
  set is the recent snapshots and indexes. See the
  [scaling guide](scaling-guide.md#resource-recommendations).
- **Retention.** No data is deleted automatically. Audit events and the
  history are product data. If an organization's audit log has to be
  bounded, delete it by `(organization_id, created_at)` range in batches;
  the index above serves that.

## Redis

| Family | Database | Lifetime |
|---|---|---|
| Sessions | `REDIS_DB` | Session lifetime (120 min) |
| Rate-limit counters | `REDIS_DB` | Their window: ≤ 60 s for per-minute limits, ≤ 1 h for hourly |
| Queues (`queues:*`) | `REDIS_DB` | Until worked; empty when idle |
| Analyzer slots (`codedna:analyzer-slots`) | `REDIS_DB` | Released per job; leases expire after the job timeout + 30 s |
| Cache | `REDIS_CACHE_DB` | Per item |

Memory follows the active users and IPs in those windows (~0.5 KB per
session), not the stored data. With 10,000 concurrent sessions, Redis needs
~5 MB. Keep `maxmemory-policy noeviction` (Phase 25). Evicting a queue or
lock key would lose a job or break mutual exclusion. Alert on memory
instead.
