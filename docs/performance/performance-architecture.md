# Performance architecture

How CodeDNA stays fast as data grows (Phase 26), what was measured, what
changed and why, and what protects it from regressing.

Related: [benchmarking](benchmarking.md) · [load testing](load-testing.md) ·
[database performance](database-performance.md) ·
[queue performance](queue-performance.md) · [scaling guide](scaling-guide.md).

## Principles

1. **Cost follows the owner, not the platform.** Every read is scoped to one
   user, project or organization and goes through an index on that owner.
   The largest owner (a project with a long history, a big organization)
   drives per-request cost, so the benchmarks measure those outliers
   explicitly, next to a broad population.
2. **Work is bounded per request.** Lists are paginated. Long append-only
   lists can be paged by cursor (no `COUNT`, no `OFFSET`). Aggregates touch
   one index entry per project, not every snapshot.
3. **The query count of an endpoint never grows with the data.** Layers are
   loaded with a fixed number of queries per page (`IN (…)`), never per row.
4. **Expensive work is asynchronous and capped.** Analysis, AI assessment,
   GitHub imports and challenge evaluation run on the queue. The analyzer's
   concurrency is a shared limit that every worker respects.
5. **Measure, change, measure again.** Each change below has a before and
   an after on the same deterministic data
   (`make benchmark-seed`, [benchmarking](benchmarking.md)).

## What was measured

The baseline was measured on the Phase 25 code. The final run uses the
same deterministic data (`BENCH_SCALE=medium`).

The medium scale has:

- 10,000 users and 1,000 organizations;
- 12,000 projects and 94,000 analyses, each with its DNA, competency,
  skill-gap and growth snapshots;
- about 1M growth observations and 1.2M audit events.

There are two outliers: a project with 10,000 analyses, and an organization
with 2,000 members, 2,000 projects and 1M audit events.

The in-process API benchmark (`make benchmark-api`) runs each endpoint 30
times through the HTTP kernel, rotating users and projects.

| Endpoint | p50 before | p50 after | p95 before | p95 after | Queries before → after |
|---|---|---|---|---|---|
| Team analytics, large organization | 592 ms | **84 ms** | 687 ms | **127 ms** | 11 → 10 |
| Team analytics, typical organization | 21 ms | 16 ms | 27 ms | 22 ms | 11 → 10 |
| Audit log, large organization, first page | 96 ms | **12.5 ms** | 120 ms | **20.5 ms** | 6 → 5 |
| Audit log, 99% deep | 385 ms (page 39,999) | **15 ms** (cursor) | 459 ms | **19 ms** | 6 → 5 |
| History point (long history) | 84 ms | **22 ms** | 106 ms | **27 ms** | 12 → 12 |
| Growth overview (long history) | 71 ms | **34 ms** | 86 ms | **42 ms** | 5 → 5 |
| History page (long history), deep | 195 ms (page) | **85 ms** (cursor) | 252 ms | **134 ms** | 10 → 9 |
| Analyses, 99% deep | 22 ms (page) | 13 ms (cursor) | 28 ms | 18 ms | 3 → 2 |
| Organization billing | 19 ms | 12 ms | 26 ms | 16 ms | **16 → 10** |
| Billing overview | 12 ms | 6 ms | 17 ms | 14 ms | **8 → 3** |
| Any team-project read | — | — | — | — | **−2** each |

Response bodies are byte-identical before and after for every endpoint
except the audit log. Its `meta` now carries cursors instead of totals.

Through Nginx with the production backend image (`make loadtest`, 16
concurrent clients on a 4-CPU host that also runs the load generator and
every service), all targets hold. See [load testing](load-testing.md).

## Bottlenecks found and what changed

| # | Bottleneck (measured) | Change | Result |
|---|---|---|---|
| 1 | Team analytics sorted every DNA/skill-gap snapshot of the **whole table** (sequential scan) to find each project's latest, four times per request | One index probe per project (`LATERAL … ORDER BY created_at DESC, id DESC LIMIT 1`) in a `MATERIALIZED` CTE shared by the figures; identical output (checked byte for byte on 31 organizations) | 592 → 84 ms; cost follows the organization, not the platform |
| 2 | Audit log: `COUNT(*)` of the whole log and `OFFSET` on every page | Keyset pagination with signed cursors (the only mode for this unbounded log) | 96 → 12.5 ms first page; 385 → 15 ms deep, constant at any depth |
| 3 | History, history point and growth sorted the project's whole history by run completion; growth's "newest assessment" scanned **all** analysis runs | Partial index `analysis_runs (project_id, completed_at DESC, id DESC) WHERE status = 'SUCCEEDED'`; the run's project stated in the growth query; neighbour lookups bounded by the index prefix | History query 11.4 → 0.3 ms; point 84 → 22 ms; growth 71 → 34 ms |
| 4 | Deep pages of long project histories walk `OFFSET` rows | Optional cursor mode (`?cursor=`) on history, analyses, source snapshots, growth timeline, GitHub imports and the billing usage ledger; page mode kept for totals | Constant time at any depth |
| 5 | Quota summary: one counter query per quota | One `IN (…)` query (read path only; consuming a quota still locks its own row) | 8 → 3 queries |
| 6 | Organization billing read the billing account three times | The loaded account is passed to the seat count | 16 → 10 queries |
| 7 | A team-project request authorized `view` twice (form request and controller), each reading the organization and membership | The ALLOW is remembered for the rest of that one HTTP request (keyed by the Request object; never denials, never writes, never in queue or console processes) | −2 queries per team-project read |
| 8 | **More queue workers than analyzer slots failed analyses**: the analyzer answers extras with `ANALYZER_BUSY`, which used up the run's 3 attempts (18 of 24 failed with 4 workers and 2 slots) | Workers take one of the analyzer's slots (a Redis semaphore sized by `ANALYZER_MAX_CONCURRENCY`) before claiming a run; a worker that waits too long hands the run to a delayed job without using an attempt | 4 workers: 0 failures, 0 retries, 148–150 analyses/min (was 2.4/min) |
| 9 | Every PHP-FPM request opened a PostgreSQL connection and authenticated (SCRAM) | Persistent connections per FPM worker in production (`DB_PERSISTENT`). PDO rolls back any transaction a request leaves open before the connection is reused (verified) | Request throughput ×2 (81 → 158 req/s), single-request p50 33 → 18.5 ms |
| 10 | GitHub status polling fetched project, connection and imports every 3 s; hidden tabs kept polling | Poll only the connection while an import runs and reload once at the end; all pollers pause while the tab is hidden | 3 → 1 request per tick; none in background tabs |

## Measured and left as is

- **History page assembly** (≈4 ms per point): PHP builds each point's DNA,
  competency, skill-gap and growth layers from Eloquent casts. The cost is
  proportional to `per_page`, not to the history's length. A full page of
  25 points stays well inside its target (p95 108–134 ms, target 500 ms).
  Changing the resources to raw attributes would risk changing their
  output.
- **Plan catalog**: request-scoped already (3 small queries, ~2 ms).
  Caching plans across requests would risk a stale plan in a billing
  decision.
- **Analyzer concurrency model**: parsing holds the GIL, so 2 threads give
  ~20% less throughput than 1 but keep small analyses moving while a large
  one runs. A process pool would nearly double throughput (measured, see
  [queue performance](queue-performance.md#analyzer)). The analyzer's
  execution-safety control forbids `multiprocessing` and `pickle` in
  application code, and that control is kept.
- **Transactions**: no transaction is held across external I/O. The
  analysis, AI, evaluation and GitHub jobs claim in a short locked
  transaction, call out with none open, and record the outcome in
  another. Uploads and imports store the object first and record the
  snapshot in a short transaction, deleting the object if that fails.
- **Rate limits and Redis**: keys expire with their windows (≤ 60 s for
  per-minute limits) or the session lifetime. Their cardinality is bounded
  by active users and IPs within those windows. See
  [database performance](database-performance.md#redis).

## Cursor vs offset pagination

| List | Mode | Why |
|---|---|---|
| Organization audit events | **cursor only** | Append-only, unbounded (millions of rows per large organization) |
| History, analyses, source snapshots, growth timeline, GitHub imports, billing usage | page (default) **or cursor** (`?cursor=`) | Append-only and long-lived; the UI shows totals in page mode |
| Projects, competencies, skill gaps, DNA, roadmaps, challenges, assessments, members, organization projects, invitations, organizations | page | Bounded by plans and quotas, or small; totals shown |
| Challenge submissions | page | At most `CHALLENGE_MAX_ATTEMPTS` (5) per challenge |

The contract is in [docs/api/README.md](../api/README.md#pagination).

## Regression protection

| What | Where |
|---|---|
| Query count independent of data size for 33 endpoints, ceiling 14 | `tests/Feature/Performance/QueryBudgetTest.php` |
| Response size per list item (history ≤ 8 KB/point, audit ≤ 1 KB/event, …) | same |
| Membership read once per team-project request; quota counters in one query; billing account read at most twice | same |
| Keyset pages complete, ordered, stable under inserts; cursors opaque, list-bound, tamper-evident; page+cursor refused | `tests/Feature/Performance/CursorPaginationTest.php` |
| The history index exists with its exact shape; owner indexes behind keyset and latest-snapshot reads | `tests/Feature/Performance/IndexesTest.php` |
| View decisions live only as long as one routed request | `tests/Unit/Policies/ViewDecisionsTest.php`, `TeamProjectAccessTest` |
| Analyzer slots: wait without using attempts, released on errors, bounded by configuration | `tests/Feature/Analysis/AnalyzerSlotsTest.php` |
| Timeout chain includes the slot wait | `tests/Unit/ConfigurationValidatorTest.php` |
| Upload and import memory stay bounded up to the 50 MiB limit | `tests/Benchmark/SourceIntakeBenchmarkTest.php` (`make benchmark-intake`) |
| Polling: one request per tick, none while hidden, stops at terminal states | `frontend/src/components/github/project-github.test.tsx`, `frontend/src/lib/polling/use-page-visible.test.tsx` |

No test asserts a wall-clock time: shared CI machines make timings flaky.
Structural limits (query counts, sizes, memory, index shape) catch the
same regressions reliably.

## Remaining bottlenecks

- **Team analytics is linear in the organization's active projects**
  (~84 ms at 1,800). Around 10,000 active projects per organization, add a
  read model: a per-organization summary row refreshed when a project's
  latest assessment changes. That was not needed at the Phase 26 targets.
- **Analyzer throughput** is one CPU-bound stream per analyzer process
  (≈180 medium analyses/min, ≈15 large/min). More analyzer instances need
  a replay cache shared across them first
  ([scaling guide](scaling-guide.md#analyzer)).
- **Per-request PHP CPU** (~15–20 ms with persistent connections) bounds a
  web node at roughly 40 requests/s per CPU core. Web workers scale
  horizontally ([scaling guide](scaling-guide.md#web-and-queue-workers)).
