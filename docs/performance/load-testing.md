# Load testing

`make loadtest` drives the running stack over HTTP, through Nginx, with
authenticated browser-equivalent sessions. It runs scenarios A–F and reports
throughput, latency percentiles, status codes, PostgreSQL connections, the
analysis queue length and a Redis key audit.

## What it runs against

`scripts/benchmark/loadtest.sh`:

1. refuses to start without the benchmark database (`make benchmark-seed`);
2. applies `docker/benchmark/compose.loadtest.yml` to the backend and the
   queue worker:
   - the **production backend image** (immutable OPcache, the production
     PHP-FPM pool, no bind mount, `APP_DEBUG=false`);
   - the benchmark database, Redis databases 5/6 and the `benchmark/`
     storage prefix;
   - persistent DB connections (`LOADTEST_DB_PERSISTENT=false` measures
     without them);
3. creates sessions (`php artisan benchmark:sessions`):
   - 400 seeded readers;
   - 40 writers, each with a fresh project and 4 unanalyzed uploads.

   The sessions are made by the code a login runs, with cookies encrypted
   as `EncryptCookies` does. Every request therefore passes the real
   session, Sanctum and CSRF middleware, without logging 440 users in over
   HTTP, which the login limits would rightly refuse;
4. runs `scripts/benchmark/loadtest.py` (Python standard library only; one
   keep-alive connection per client thread);
5. on exit, even on failure, deletes the sessions file, flushes Redis
   databases 5/6 and restores the development backend and worker.

APP_ENV stays `local` in the overlay only because the edge is plain HTTP
(production configuration refuses insecure URLs and cookies). Everything
that affects speed is the production setup.

```bash
make loadtest                                                     # all scenarios, 16 clients, 30 s each
make loadtest LOADTEST_ARGS="--scenarios A,B --concurrency 32 --duration 60 --json out.json"
```

## Scenarios

| | Scenario | Traffic |
|---|---|---|
| A | Authenticated reads | `me`, `profile`, `billing`, project list |
| B | Project, DNA, history | project, DNA, history (cursor, 10 per page), growth, competencies, skill gaps, roadmaps, analyses |
| C | Organization, team | organizations, organization, members, projects, analytics; audit (cursor) and billing for admins |
| D | Analysis submissions | writers start analyses of fresh uploads (real jobs on the real analyzer) and repeat earlier ones (idempotent replays) |
| E | Mixed | 70% A+B, 20% C, 10% D |
| F | Concurrent writes | writers create projects concurrently; the active-project quota must hold |

Submissions are paced at 8 per writer per minute, below the analysis-create
limit (10 per minute), so D measures the system, not the rate limiter. The
limiter itself is covered by feature tests.

## Results (Phase 26, medium scale)

These numbers come from one 4-CPU host that also ran PostgreSQL, Redis,
MinIO, the analyzer, the worker, the frontend and the load generator.
Treat them as a floor, not a capacity figure.

### Persistent connections

Scenario A at 16 clients:

| | Throughput | Single-request p50 |
|---|---|---|
| Connect per request | 81 req/s | 33 ms |
| Persistent (`DB_PERSISTENT=true`) | 158 req/s | 18.5 ms |

Scenario B went from 67 to 129 req/s.

### Concurrency sweep

Scenario A with connect-per-request: 1 client gave 28 req/s; 4, 8 and 16
clients all gave ~81 req/s. Throughput stops at the host's CPU: PHP-FPM, the
load generator and PostgreSQL share 4 cores. Latency grows with the queue in
front of FPM, not with the data.

### Final run

Persistent connections, 16 clients, 30 s per scenario:

| Scenario | Throughput | p95 | Errors |
|---|---|---|---|
| A | 185 req/s | 159 ms | 0 |
| B | 128 req/s | 255 ms | 0 |
| C | 123 req/s | 239 ms | 0 |
| D (new submission → 202) | paced | 57 ms | 0 |
| E (A / B / C parts) | — | 217 / 291 / 250 ms | 0 |
| F (create project) | — | 209 ms | 0 unexpected |

- **Quota under concurrency**: no writer ever got more than its 2 remaining
  active projects. Every other concurrent create got `402` (quota) or `429`
  (rate limit), never a `500` or an extra project.
- **PostgreSQL**: at most 17 connections (FPM workers plus the queue
  worker), out of `max_connections` 100.
- **Queue**: submissions were taken up by the worker as they arrived. Its
  length went back to zero after D.
- `/up` p50 6.4 ms; `/api/v1/health` p50 24 ms (it checks the database,
  Redis, storage and the analyzer).

### Redis after a run

| Key family | TTL | Size |
|---|---|---|
| Sessions | 7,200 s (session lifetime) | ~533 B each |
| Rate-limit counters | ≤ 60 s for per-minute limits; ≤ 1 h for hourly | small |
| Queue lists | none (they are queues) | drained to empty |

No unbounded or non-expiring family was found. See
[database performance](database-performance.md#redis).

## Targets

The project's targets for a single web node with persistent connections:

- reads p95 < 300 ms at the measured concurrency;
- zero 5xx errors;
- quotas exact under concurrent writes;
- DB connections well under the server limit.

All held. When a run misses a target, compare `--show-queries` output from
`make benchmark-api BENCH_API_ARGS="--filter=… --show-queries"` for the
slow endpoint before tuning infrastructure.
