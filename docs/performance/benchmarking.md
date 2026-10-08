# Benchmarking

How to reproduce every number in [performance architecture](performance-architecture.md).
All benchmarks run against the development Docker Compose stack, on data that
is isolated from development data and removed with one command.

## Isolation

| Resource | Benchmark | Development |
|---|---|---|
| PostgreSQL database | `codedna_benchmark` | `codedna` |
| Redis (queue, sessions, rate limits / cache) | databases 5 / 6 | 0 / 1 |
| Object storage prefix | `benchmark/` | none |
| Local files | `backend/storage/app/benchmark/` (git-ignored, excluded from images) | — |

Every `benchmark:*` command refuses to run unless `APP_ENV` is `local` or
`testing` **and** the connected database's name ends in `_benchmark`
(`BenchmarkGuard`). A misconfigured command cannot seed or wipe development
or production data.

Generated data is never committed. The seed password
(`benchmark-only-not-a-secret`) is a constant for throwaway local data, not
a credential.

## Commands

```bash
make benchmark-seed BENCH_SCALE=small   # or medium / large; drops and recreates codedna_benchmark
make benchmark-api                      # in-process API: latency, query count, response size per endpoint
make benchmark-queue                    # real workers and analyzer at 1, 2 and 4 workers
make benchmark-analyzer                 # analyzer stages, peak memory, concurrency
make benchmark-intake                   # upload and GitHub import time and memory up to 50 MiB
make loadtest                           # HTTP scenarios A–F through Nginx (load-testing.md)
make benchmark-clean                    # removes the database, Redis 5/6, stored objects and local files
```

`make benchmark-sources` writes the deterministic synthetic archives used by
the commands above (`scripts/benchmark/make_sources.py`). The profiles are:

| Profile | Files |
|---|---|
| small | 13 |
| medium | 121 |
| large | 1,201 |
| xlarge | 6,001 |
| near-limit | ~45 MB |

The same seed always produces byte-identical archives.

## Scales

Per-request cost depends on the largest **owner** (one project's history,
one organization's members, projects and audit log), not on table size. So
every scale has a broad population and two outliers.

| Scale | Users | Orgs | Projects | Analyses | Audit events | Long history | Large org (members / projects / events) | Seed | Disk |
|---|---|---|---|---|---|---|---|---|---|
| small | 1,000 | 50 | 2,500 | ~12,000 | ~110,000 | 1,000 | 500 / 500 / 100,000 | 52 s | 327 MB |
| medium | 10,000 | 1,000 | 12,000 | ~94,000 | ~1.2M | 10,000 | 2,000 / 2,000 / 1,000,000 | 452 s | 2.5 GB |
| large | 10,000 | 1,000 | 52,000 | ~1M | ~10M | 10,000 | 2,000 / 2,000 / 1,000,000 | — | ~30 GB (est.) |

Phase 26 measured `medium`. `large` is defined for hosts with the disk to
spare and was **not** run here (the development host had ~5 GB free).
Because reads go through owner indexes, the per-request numbers for the
outliers do not depend on the population size. The broad population sizes
the tables, caches and maintenance work, and
[database performance](database-performance.md) extrapolates those from the
measured medium sizes.

### How the data is made

1. **Template through the real pipeline.** A template user is created,
   with a project, two real uploads, real analyses, every derived snapshot,
   a roadmap and an organization. The application's own actions, jobs and
   analyzer produce all of it, so the payload shapes are exactly
   production's.
2. **Cloned in SQL.** `generate_series` copies the template's rows with
   deterministic ids (`0` + a two-digit table code + a 23-digit sequence:
   valid ULIDs that sort by insertion) and spread timestamps. The column
   list is read from `information_schema`, so new columns are carried
   automatically.
3. **Statistics.** `ANALYZE` runs at the end so plans match a
   settled database.

## API benchmark

`php artisan benchmark:api` sends each endpoint through the HTTP kernel
(routing, middleware, policies, resources), `--iterations` times, rotating
between seeded users and projects. It reports per endpoint:

- p50, p95 and max latency;
- the query count;
- the response size.

`--filter=analytics` selects endpoints. `--show-queries` prints the SQL of
one iteration. Through make:
`make benchmark-api BENCH_API_ARGS="--filter=analytics --show-queries"`.

The rate limiters are lifted inside this one process so 30 iterations can
run back to back. HTTP-level behaviour, rate limits included, is measured
by the [load test](load-testing.md).

The deep cursors (99% into a list) are computed from the data. The command
compares deep `?page=N` with the equivalent `?cursor=`.

## Queue benchmark

`php artisan benchmark:queue --workers=1,2,4 --analyses=24` uploads real
archives, starts real analyses and runs real workers against the real
analyzer. It respawns workers until every run is terminal and reports:

- enqueue p50/p95;
- queue wait;
- execution time;
- throughput;
- failures and retries;
- peak PostgreSQL connections, sampled from `pg_stat_activity`.

## Analyzer benchmark

`scripts/benchmark/analyzer_bench.py` runs inside the analyzer image, with
the service's CPU and memory limits. Per profile, it measures:

- time per stage (extract, parse, metrics, scoring);
- peak RSS;
- throughput at 1–4 concurrent analyses on the service's own thread pool.

A process-pool section is labelled **REFERENCE ONLY**: it shows what the
GIL costs, but the analyzer does not use (and must not use) process pools.

## Intake benchmark

`tests/Benchmark/SourceIntakeBenchmarkTest.php` is opt-in and outside the
default suites. It runs real uploads and fake-GitHub imports of every
profile up to the 50 MiB limit and asserts memory bounds. Before measuring,
a warm-up run pays the one-time S3 client initialization (~25 MB).

## Results

Raw JSON results go to `backend/storage/app/benchmark/results/`
(git-ignored). The figures quoted in these documents come from the Phase 26
runs on one 4-CPU development host with 16 GB RAM. Every service ran on
that host, the load generator included. Absolute numbers on other hardware
will differ. The before/after ratios and query counts are what to compare.
