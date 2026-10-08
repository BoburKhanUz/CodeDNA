# Queue performance

Throughput and safety of the asynchronous work: analyses, AI assessments,
challenge evaluation and GitHub imports.

## Topology

One Redis queue connection serves four queues. Each `queue:work` process
takes them in priority order: `analysis`, `assessment`, `challenge`,
`github`. In production the worker:

- runs the immutable image;
- uses `--timeout=${ANALYSIS_JOB_TIMEOUT_SECONDS}` (330 s);
- restarts itself after 500 jobs or one hour.

Every job is idempotent:

- it claims its run with a locked status transition, so a duplicate
  delivery finds the run already claimed or finished and does nothing;
- submissions carry idempotency keys, so repeating one returns the
  existing run (load test scenario D).

## Analyzer slots

The analyzer runs at most `ANALYZER_MAX_CONCURRENCY` analyses at once and
answers more with `ANALYZER_BUSY`. Before Phase 26, a worker that got
`BUSY` released the job with backoff and **used one of its 3 attempts**.
With more workers than slots, analyses failed under ordinary load:

| 24 medium analyses, analyzer concurrency 2 | Throughput | Failed | Retries |
|---|---|---|---|
| 4 workers, before | 2.4 / min | **18** | 42 |
| 4 workers, after | 148–150 / min | 0 | 0 |

Now `AnalyzeSourceSnapshot` takes one of the analyzer's slots before
claiming the run:

```php
Redis::funnel('codedna:analyzer-slots')
    ->limit(ANALYZER_MAX_CONCURRENCY)          // the analyzer's own limit
    ->releaseAfter(job timeout + 30 s)         // a crashed worker's slot frees itself
    ->block(ANALYZER_SLOT_WAIT_SECONDS)        // wait up to 20 s
```

- A run is claimed, and an attempt counted, only while a slot is held.
  The slot is released as soon as the analyzer has answered, before
  results are stored.
- If no slot frees within the wait, the worker logs `analysis.deferred`
  and hands the run to a fresh job delayed by 5 s. The current job ends
  successfully, so no attempt is used and the worker moves on.
- Errors release the slot (`AnalyzerSlotsTest`).
- `ConfigurationValidator` refuses a `max_concurrency` outside 1–64, and
  a timeout chain where analyzer timeout + slot wait ≥ the job timeout.

Set `ANALYZER_MAX_CONCURRENCY` to the **same value** on the backend and the
analyzer. A lower backend value wastes analyzer capacity. A higher one
brings back `BUSY` retries, which are bounded but slow.

## Measured throughput

`make benchmark-queue` sent 24 medium analyses (121 files) through real
workers and the real analyzer (2 slots):

| Workers | Analyses / min | Failed | Retries | Enqueue p95 | Upload p95 | Queue wait p50 | Peak DB connections |
|---|---|---|---|---|---|---|---|
| 1 | 103 | 0 | 0 | 17 ms | 43 ms | 7 s | 2 |
| 2 | 131 | 0 | 0 | 20 ms | 48 ms | 6 s | 3 |
| 4 | 150 | 0 | 0 | 19 ms | 43 ms | 5 s | 5 |

Throughput varies by about ±10% between runs on the shared host. An earlier
run measured 116 / 142 / 148 per minute. Queue wait is the time the 24
analyses, all submitted at once, waited for a worker.

Workers beyond the analyzer's slots add little analysis throughput, but
they keep assessment, challenge and import jobs moving while analyses
wait. Run **analyzer slots + 1 or 2 workers**.

## Analyzer

`make benchmark-analyzer` ran on 2 CPUs inside the analyzer image:

| Profile | Files | Time | Peak RSS |
|---|---|---|---|
| small | 13 | 35 ms | 39 MB |
| medium | 121 | 392 ms | 41.5 MB |
| large | 1,201 | 3.9 s | 52 MB |
| xlarge | 6,001 | 18.5 s | 96 MB |

Parsing (Tree-sitter) is ~90% of the time. Memory is bounded by the archive
limits, not by the request rate.

Concurrent medium analyses on the service's thread pool:

| Threads | Analyses / min |
|---|---|
| 1 | 182 |
| 2 | 147 |
| 3 | 131 |
| 4 | 113 |
| *Process pool, 2 (reference only)* | *283* |

The analysis holds the GIL, so threads do not add throughput; they cost
some. Concurrency 2 is still the default because it keeps a small analysis
from waiting behind a large one: the second slot is latency fairness, not
throughput.

A process pool would nearly double throughput, but the analyzer's
execution-safety control forbids `multiprocessing` and `pickle` in
application code. That control was kept, and the measurement is only
recorded. To scale analysis throughput, run more analyzer capacity
([scaling guide](scaling-guide.md#analyzer)).

## Uploads and GitHub imports

`make benchmark-intake`, real storage (MinIO), GitHub latency excluded:

| Archive | Upload | Upload peak memory | GitHub import |
|---|---|---|---|
| small | 52 ms | 1.1 MB | 62 ms |
| medium | 52 ms | 1.2 MB | 77 ms |
| large | 83 ms | 1.2 MB | 105 ms |
| xlarge | 218 ms | 5.2 MB | 260 ms |
| near limit (45 MB) | 815 ms | 2.5 MB | 1.13 s |

Uploads stream to storage, so memory does not grow with archive size. The
import's in-memory peak (47 MB near the limit) comes from the test's fake
GitHub, which holds the archive. The real client streams the download to a
temporary file.

## Fairness and monopolization

One user cannot fill the queue:

| Limit | Value |
|---|---|
| Uploads | 5 / min and 60 / hour |
| Analysis submissions | 10 / min |
| GitHub imports | 5 / min and 30 / hour |
| Plan quotas (monthly analyses, active projects) | per plan |
| Active analyses per snapshot and result type | one, by a unique partial index |

A per-user fairness funnel was considered and rejected. It would release
jobs while they wait, and every release uses an attempt: the failure mode
fixed above.

## Monitoring

- **Queue length**: `LLEN queues:analysis` (and the other queues). Over
  ~2 minutes of work per worker (e.g. > 300 analyses with 2 slots), add
  analyzer capacity.
- **Deferrals**: count `analysis.deferred` log lines. A steady stream means
  more workers than slots (harmless), or a stuck slot (check analyzer
  health).
- **Failures**: count `analysis_runs` with `status = 'FAILED'` in the last
  hour, grouped by `failure_code`.
- **Horizon** is not used. `queue:monitor` thresholds can be scheduled if
  alerts are needed.
