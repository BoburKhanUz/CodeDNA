# Scaling guide

When and how to scale each CodeDNA component. Each threshold comes from a
Phase 26 measurement ([performance architecture](performance-architecture.md)),
not from a rule of thumb. Out of scope by design: Kubernetes, Kafka or
RabbitMQ, ClickHouse, sharding, multi-region, a service mesh. The
deployment remains Docker Compose
([production deployment](../operations/production-deployment.md)).

## What scales how

| Component | State | Scales | Limit |
|---|---|---|---|
| Nginx | none | horizontally behind a load balancer | — |
| Frontend (Next.js) | none | horizontally | — |
| Backend (PHP-FPM) | none (sessions, cache, locks and rate limits are in Redis) | horizontally; any node serves any request; no sticky sessions | DB connections |
| Queue workers | none (jobs claim runs under locks; idempotent); `challenge` jobs need the evaluator's spool | horizontally, any number | analyzer slots (below) |
| Scheduler | — | **exactly one** | its commands assume a single runner |
| Analyzer | per-process replay cache and run registry | **one instance**; scale it vertically | CPU |
| Evaluator | per-run sandbox | one instance; vertically | CPU, sandbox runtime |
| PostgreSQL | primary | vertically, then read replicas (not used yet) | memory for the hot set |
| Redis | queues, sessions, locks | vertically (memory is small) | `noeviction` memory |
| MinIO / S3 | objects | the provider's | — |

## Web and queue workers

**Web.** One web node with persistent connections served 185 req/s of
authenticated reads (scenario A) and 128 req/s of project reads
(scenario B) on a shared 4-CPU host. Per-request PHP CPU is ~15–20 ms.

- Add a node when FPM is CPU-bound: p95 rising while PostgreSQL is idle.
- Plan for ~40 req/s per backend CPU core.
- The node needs the same `APP_KEY`, Redis and database. Uploads go
  straight to object storage.
- The one local resource is the **challenge spool** volume
  (`challenge-spool`), shared with the evaluator. The backend reads the
  evaluator's heartbeat from it, and the queue worker hands it
  submissions. On several hosts, run the evaluator with the workers that
  take the `challenge` queue. Give other web nodes and workers the spool
  through a shared volume, or run them with `--queue` lists that leave
  `challenge` out.

**Queue.** Workers are safe to add at any time:

- runs are claimed under row locks, so no job runs twice;
- analyzer slots are shared through Redis, so extra workers wait for a
  slot instead of failing runs ([queue performance](queue-performance.md#analyzer-slots)).

Run analyzer slots + 1–2 workers, so assessments, challenges and imports
keep moving while analyses wait. Add more workers if the `assessment`
queue grows: AI calls are I/O-bound and do not use analyzer slots.

## Analyzer

The analyzer keeps two things per process: the replay cache that rejects
re-sent signed requests, and the registry of in-flight runs. A second
instance behind a load balancer would weaken replay protection, so the
analyzer runs as **one instance**.

To raise analysis throughput:

1. **Vertically**: give the container more CPU. Raise
   `ANALYZER_MAX_CONCURRENCY` on **both** the analyzer and the backend.
   The two values must match. Throughput per slot is bounded by the GIL
   (182 medium analyses/min with 1 slot on 2 CPUs), so extra slots mostly
   help latency fairness.
2. **Horizontally (future work)**: move the replay cache to Redis first.
   Then run N analyzer instances, each with its own slot pool and URL.
   Until that change, do not run more than one.

Thresholds:

- **Sustained > 100 analyses/min**: review analyzer CPU.
- **Many large archives**: 1,200 files take ~3.9 s, 6,000 files ~18.5 s
  (up to 96 MB RSS). Keep the memory limit at
  `ANALYZER_MAX_CONCURRENCY × 200 MB` or more.

## PostgreSQL

| Signal | Action |
|---|---|
| Cache hit ratio < 99% in steady state | more RAM; `shared_buffers` ≈ 25% of RAM, `effective_cache_size` ≈ 70% |
| Connections > 80% of `max_connections` | PgBouncer in **session** mode, or fewer FPM children per node |
| `analysis_runs` dead tuples > 10% | lower its autovacuum scale factor ([database performance](database-performance.md#maintenance)) |
| Team analytics p95 > 300 ms for one organization (≈ 5,000+ active projects) | a per-organization summary read model ([performance architecture](performance-architecture.md#remaining-bottlenecks)) |
| CPU-bound on reads after the above | a streaming read replica for read-only endpoints (requires routing work; not built) |

## Redis

At 10,000 concurrent sessions, sessions take ~5 MB, rate limits less, and
queues are near zero when drained. The production 384 MB `maxmemory` with
`noeviction` leaves headroom of two orders of magnitude. Alert at 70%
memory. Never switch to an evicting policy: evicting a queue or lock key
loses a job or breaks mutual exclusion.

## Resource recommendations

These sizes are starting points derived from the measurements. Confirm
yours with `make loadtest` and `make benchmark-api` on a copy of production
sizes.

| Profile | Users | Web | Queue | Analyzer | PostgreSQL | Redis |
|---|---|---|---|---|---|---|
| **Small** (one host, the `docker-compose.prod.yml` defaults) | ≤ 1,000 active, ≤ 20 req/s | 1 node, 2 CPU / 768 MB, 16 FPM children | 1 worker, 1 CPU / 512 MB | 2 CPU / 1.5 GB, concurrency 2 | 2 CPU / 1 GB (`shared_buffers` 256 MB) | 512 MB |
| **Medium** | ≤ 10,000 active, ≤ 100 req/s, ≤ 100 analyses/min | 2 nodes × 2–4 CPU | 3–4 workers | 4 CPU / 2 GB, concurrency 2–3 | 4 CPU / 8 GB (`shared_buffers` 2 GB), SSD | 512 MB |
| **Large** | ≥ 10,000 active, ≤ 400 req/s | 4+ nodes × 4 CPU, load balancer, PgBouncer (session mode) | 4–6 workers | 8 CPU / 4 GB, concurrency 4 (single instance) | 8+ CPU / 32 GB (`shared_buffers` 8 GB), NVMe | 1 GB |

On the single-host Compose deployment, PostgreSQL settings are set with
`command: ["postgres", "-c", "shared_buffers=256MB", "-c", "effective_cache_size=768MB"]`
in an override file. The container's memory limit has to cover
`shared_buffers` plus per-connection work memory.

## Before scaling anything

1. Find the slow endpoint
   (`make benchmark-api BENCH_API_ARGS=--filter=…` on copied
   sizes, or the access log's request times).
2. Look at its queries (`BENCH_API_ARGS=--show-queries`,
   `pg_stat_statements`).
3. Fix the query or index first. Phase 26's largest wins (7× on team
   analytics, 25× on deep audit pages) came from queries, not hardware.
