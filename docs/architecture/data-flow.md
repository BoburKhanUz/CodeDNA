# Data Flow — Analysis Pipeline

This document describes how source arrives and how one analysis moves
through the system. Source upload (Phase 07) and the analysis pipeline
(Phase 10: queue, analyzer client, verification, persistence) are
and DNA scoring (Phase 11) are implemented.

Related: [ADR-003](../decisions/ADR-003-storage.md) (storage),
[ADR-005](../decisions/ADR-005-service-communication.md) (communication),
[internal analyzer contract](../api/internal-analyzer-contract.md).

## Domain terms

The entities are implemented in Phase 05; see [data-model.md](data-model.md).

| Term | Meaning |
|---|---|
| **Project** | A user-owned project with one source origin (`UPLOAD`, or `REPOSITORY` with an HTTPS URL) |
| **Source snapshot** | An immutable reference to an archive in object storage (key, SHA-256, size, per-project version) |
| **Analysis run** | One execution of the pipeline against one snapshot for one result type (`foundation` or `static_analysis`). Re-analysis after a failure creates a **new run**; earlier runs are kept. Job retries for transient errors happen **within** a run. |
| **Analysis result** | The verified analyzer response of one `SUCCEEDED` run (at most one per run, immutable) |
| **DNA snapshot** | The immutable score of one `SUCCEEDED` `static_analysis` run, at most one per scoring version ([dna-scoring-v1.md](dna-scoring-v1.md)) |

## End-to-end sequence (MVP)

```text
Browser (UI)     Nginx          Laravel API         Object storage      Redis queue     Laravel worker        Analyzer
   │  upload ZIP    │                │                    │                  │                 │                    │
   │───────────────►│───────────────►│ inspect ZIP, hash  │                  │                 │                    │
   │                │                │──── put object ───►│                  │                 │                    │
   │                │                │ record source snapshot (v1, v2, …)    │                 │                    │
   │  start analysis│                │                    │                  │                 │                    │
   │───────────────►│───────────────►│ create analysis run (QUEUED)          │                 │                    │
   │                │                │── dispatch AnalyzeSourceSnapshot (after commit) ►│       │                    │
   │                │◄── 202 {id} ───│                    │                  │                 │                    │
   │                │                │                    │                  │── job ─────────►│ run: RUNNING       │
   │                │                │                    │◄── presign GET ─────────────────────│                    │
   │                │                │                    │                  │                 │── signed POST ────►│
   │                │                │                    │◄──────────────── fetch archive ──────────────────────────│
   │                │                │                    │                  │                 │                    │ analyze
   │                │                │                    │                  │                 │◄── signed result ──│
   │                │                │                    │                  │                 │ verify, persist    │
   │                │                │                    │                  │                 │ run: SUCCEEDED     │
   │  poll status   │                │                    │                  │                 │                    │
   │───────────────►│───────────────►│ status / result    │                  │                 │                    │
```

The browser runs the Next.js UI. In the single-origin topology (ADR-006), its
API calls go through Nginx straight to Laravel, not through the Next.js server.

## Source upload

**Implemented in Phase 07.** `POST /api/v1/projects/{project}/source-snapshots`
([API reference](../api/README.md#source-snapshots)) runs
`App\Actions\Snapshots\StoreUploadedSource`:

```text
auth:sanctum ─► throttle:source-upload ─► owner check (404 for others)
  ─► project ACTIVE? (409 PROJECT_ARCHIVED)  ─► source_type UPLOAD? (409 INVALID_SOURCE_TYPE)
  ─► inspect the ZIP (ZipArchiveInspector; nothing extracted, nothing executed)
  ─► SHA-256 + size of the archive bytes (server-computed)
  ─► Idempotency-Key seen before? ─► return that snapshot (200, Idempotent-Replayed: true)
  ─► new snapshot ULID ─► PUT projects/{project}/snapshots/{snapshot}/source.zip (private)
  ─► RecordSourceSnapshot: lock project row, version = max + 1, insert ─► 201
         └─ insert failed ─► DELETE that object (best effort; on failure log its key) ─► error
```

### Archive inspection

The uploaded file is never extracted, executed or interpreted. The
inspector reads the ZIP structures and streams each entry's compressed bytes
through zlib only to measure them. It accepts a single-disk ZIP (ZIP64
included) with stored or deflated, unencrypted entries, and rejects:

| Problem | Code |
|---|---|
| Not a ZIP by content (the name and MIME type are ignored), truncated, corrupt, encrypted, unsupported compression, no files, duplicate entries, a path that is both a file and a directory, data before the first entry, CRC or size mismatches | `SOURCE_ARCHIVE_INVALID` |
| Paths with `..`, absolute paths, drive letters (`C:`), backslashes, `.` or empty segments, control characters, over `SOURCE_MAX_PATH_LENGTH`; symbolic links, devices, FIFOs; overlapping entries; a local header naming a different file than the central directory; an entry that decompresses to more than it declares (zip bomb) | `SOURCE_ARCHIVE_UNSAFE` |
| Archive over `SOURCE_MAX_ARCHIVE_BYTES` | `SOURCE_ARCHIVE_TOO_LARGE` (413) |
| Declared or real total over `SOURCE_MAX_UNCOMPRESSED_BYTES` | `SOURCE_UNCOMPRESSED_SIZE_EXCEEDED` |
| More than `SOURCE_MAX_FILES` files (or twice that many entries of any kind) | `SOURCE_FILE_COUNT_EXCEEDED` |
| One file over `SOURCE_MAX_SINGLE_FILE_BYTES` | `SOURCE_FILE_TOO_LARGE` |

Declared sizes are checked first, so a huge declared size is rejected
without decompressing anything. Real sizes are then verified by streaming
in 8 KiB chunks, which bounds memory however the archive is crafted. The
strict layout rules (no prepended data, no overlaps, matching local
headers) mean every ZIP reader, including the future analyzer's, sees the
same entries. Rejection messages and logs never contain entry names or
contents; the log records a fixed reason identifier (e.g. `path_traversal`).

The inspector's accepted format is tested against archives produced by
Python's `zipfile` (seekable and streaming), Info-ZIP `zip -r` and
`git archive` (`backend/tests/Support/RealWorldZips.php`).

### Storage and cleanup

- The object is written through Laravel's filesystem abstraction (the
  `sources` disk, S3 API), never with MinIO-specific APIs. The bucket is
  private; no URL to an object is ever returned to a client.
- The database transaction cannot roll back an object. Therefore the object
  key is derived from a snapshot ID generated for this attempt: if recording
  the snapshot fails, exactly that object is deleted. If the delete fails
  too, `Orphaned source object could not be deleted.` is logged with the
  disk, key, project and snapshot IDs (no exception message, no
  credentials). A reconciliation job for such orphans is future work.
- A storage outage returns `503 SERVICE_UNAVAILABLE` and creates nothing.
- If the project is archived while an upload is in flight, the insert is
  refused under the project lock, the object is deleted and the client gets
  `409 PROJECT_ARCHIVED`.

### Idempotency

Clients may send `Idempotency-Key` (8–128 of `A–Z a–z 0–9 . _ : -`). Its
SHA-256 is stored on the snapshot, unique per project. A retry with the same
key and the same bytes returns the original snapshot; the same key with
different bytes is `422 IDEMPOTENCY_KEY_REUSED`. Concurrent duplicates are
resolved by the unique index: the loser deletes its object and returns the
winner's snapshot. Without a key, every upload creates a new snapshot, even
for identical bytes (re-uploading is legitimate). The web UI sends one key
per chosen file.

### Handoff to the analyzer

The analyzer side exists (Phases 08–09): given a signed request with a
short-lived pre-signed GET URL plus `source_hash` and `size_bytes`, it
downloads the object from an allow-listed host, re-checks size and SHA-256,
applies its own extraction limits (the upload inspection does not replace
them), discovers files and returns a versioned **foundation result**, or,
when asked for `static_analysis`, also parses them and returns a
**static-analysis result** with IR 1.1, metrics and findings
([analyzer.md](analyzer.md), [contract](../api/internal-analyzer-contract.md)).
The Laravel side (Phase 10) is the analysis pipeline below: the run, the
queued job, the client that signs requests and verifies results, and
persistence. `make verify` exercises it end to end through the public API,
the `queue` worker and the analyzer.

## Analysis pipeline (Phase 10)

```text
Project ─► Source snapshot ─► POST …/analyses ─► AnalysisRun (QUEUED) ─► Redis "analysis" queue
   ─► AnalyzeSourceSnapshot job (claim: RUNNING) ─► AnalyzerClient
        fresh pre-signed URL ─► signed POST /internal/v1/analyze ─► analyzer (foundation | static_analysis)
   ◄─ verify: signature ─► JSON ─► schema ─► run ID / request ID / result type / contract ─► result_hash
   ─► persist (one transaction): run SUCCEEDED + analysis_results row   |   or FAILED with a safe code
   ─► GET …/analyses/{run}, GET …/analyses/{run}/result
```

| Piece | Where |
|---|---|
| API | `AnalysisController` (`/api/v1/projects/{project}/analyses`), [API reference](../api/README.md#analyses) |
| Start (idempotency policy) | `App\Actions\Analysis\StartAnalysis` |
| Job | `App\Jobs\AnalyzeSourceSnapshot` on queue connection and queue `analysis` |
| Analyzer client | `App\Services\Analyzer\AnalyzerClient` (+ `HmacSigner`, `CanonicalJson`, `JsonSchemaValidator`, `AnalyzerErrorMap`) |
| Persistence | `App\Actions\Analysis\PersistAnalysisResult`, table `analysis_results` |
| Stale runs | `php artisan analysis:fail-stale`, scheduled every five minutes |

Laravel owns the lifecycle, authorization, queueing, retries and
persistence; the analyzer owns download, archive safety, discovery, parsing,
metrics and findings (ADR-005). Neither result type is a score: a stored
`static_analysis` result is scored afterwards ([DNA scoring](#dna-scoring-phase-11)).

### Result types

A run asks the analyzer for exactly one result type, chosen when it is
started and never changed: `foundation` (default; inventory, IR 1.0) or
`static_analysis` (opt-in; parsing, IR 1.1, metrics, findings). These are
the analyzer's own result types ([contract §4](../api/internal-analyzer-contract.md#result-types));
the run sends it as `options.result_type`, and a result of the other type is
rejected.

### Idempotency policy

A **logical analysis** is a (project, source snapshot, result type) triple;
the project determines the user. `POST …/analyses` resolves to it:

| Existing runs for the same snapshot and result type | Response |
|---|---|
| one `QUEUED` or `RUNNING` | `200`, that run (`Idempotent-Replayed: true`); no new job |
| one `SUCCEEDED` (and none active) | `200`, that run: a successful result is never recomputed silently |
| none, or only `FAILED` / `CANCELLED` | `202`, a **new** `QUEUED` run and one job; the failed runs stay as history |

So a retry after a failure is explicit (the client asks again and gets a new
run, with its own ID), and a successful run is never re-executed. Different
result types, and different snapshots, are independent analyses.

Concurrency is handled in the database: `StartAnalysis` decides inside a
transaction that locks the project row (concurrent starts serialize), and a
partial unique index allows at most one `QUEUED`/`RUNNING` run per
(snapshot, result type) as a backstop. `AnalysisConcurrencyTest` runs ten
forked processes against PostgreSQL at the same instant: one run, created
once, returned to all ten.

### Queue job

`AnalyzeSourceSnapshot` carries the run ID and a random claim token, nothing
else (no URL, key, hash or secret; asserted on the real Redis payload). Each
execution:

1. **Claims** the run in a transaction holding its row lock. `QUEUED` becomes
   `RUNNING`. A `RUNNING` run is resumed only by the job holding its
   **lease** (same claim token: a released retry, or a redelivery after a
   crash), or by any job once the lease has expired (job timeout + 30 s,
   extended by the backoff while a retry waits). Terminal runs and runs
   leased by another live job are left alone, so duplicate jobs and
   duplicate workers do nothing.
2. Records the attempt (number, a **new request ID**, start time) in
   `metadata.attempts`. The attempt count is bounded by
   `ANALYZER_MAX_ATTEMPTS` across releases, redeliveries and duplicate jobs.
3. Calls the analyzer (`AnalyzerClient`) with a **fresh pre-signed URL**.
4. Persists the verified result, or handles the failure (below).

If the worker kills the job (timeout), `failed()` marks the run `FAILED`
(`ANALYZER_TIMEOUT`). A result that arrives for a run that ended meanwhile
(stale sweeper) is discarded.

### Result verification

Nothing from the analyzer is trusted until every check passes; any failure
marks the run `FAILED` and **nothing is stored**:

1. HTTP: no redirects are followed; unsigned responses are never results.
2. Response HMAC signature over status, path, request ID and the exact body,
   with a fresh timestamp (±300 s), compared in constant time (current and,
   during rotation, previous secret).
3. The body is a JSON object.
4. It validates against the published JSON Schema of the requested result
   type (`packages/api-contracts/analyzer/v1`, mounted read-only at
   `/var/www/contracts`), so a result of the other type fails.
5. `analysis_run_id`, `request_id` and `result_type` equal the request's;
   `contract_version` has major 1; analyzer and IR versions are present.
6. `result_hash` equals the SHA-256 of the canonical JSON recomputed in
   Laravel (`CanonicalJson`: byte-identical to the analyzer's Python
   canonicalization, tested on real results and 3,000 float values).

### Persistence of a result

`PersistAnalysisResult`, in one transaction:

1. Lock the run row. If it is no longer `RUNNING`, store nothing (internal
   contract §6: results for a terminal run are ignored).
2. Mark it `SUCCEEDED` with `result_hash` and the versions (`analyzer`, `ir`,
   `contract`, and `metrics` for static analysis; `scoring` stays `NULL`).
3. Insert the `analysis_results` row: the **verified response body as
   received** (JSONB, so it can be re-verified against `result_hash` at any
   time), its result type, hash, versions and size.

Successful runs and their results are immutable. A new snapshot, or a new
request after a failure, creates a new run; nothing overwrites history.

### DNA scoring (Phase 11)

After `PersistAnalysisResult` stored a `static_analysis` result, the job
calls `App\Actions\Dna\CalculateDnaSnapshot` for the run. It re-verifies
the stored result against the run's `result_hash`, scores it with the
configured scoring version (`CODEDNA_SCORING_VERSION`, 1.0.0) and inserts
one immutable DNA snapshot per (run, scoring version). Scoring reads only
the stored result: no analyzer call, no source. It is best effort for the
run: a scoring failure is logged (`dna.scoring_failed`, with a failure
code) and the run stays `SUCCEEDED`; `php artisan dna:score <run>` or
`dna:score --missing` scores it later (also runs that succeeded before
Phase 11, or every run again under a new scoring version). Foundation
results are never scored. Details: [dna-scoring-v1.md](dna-scoring-v1.md).

## Run state machine

```text
               worker claims the job
 QUEUED ──────────────────────────────► RUNNING ──── verified result ────► SUCCEEDED
    │                                     │  ▲
    │                                     │  └── retryable failure: release with backoff (stays RUNNING)
    │                                     ├── non-retryable failure / attempts exhausted / worker timeout / stale ──► FAILED
    │                                     └── cancelled ──► CANCELLED
    ├── dispatch failure / stale ──► FAILED
    └── cancelled ──► CANCELLED
```

Transitions are enforced by `AnalysisRunStatus::canTransitionTo()` and the
`AnalysisRun` model ([data-model.md](data-model.md#lifecycle)); terminal
states never change (`SUCCEEDED → RUNNING`, `SUCCEEDED → FAILED` and
`FAILED → QUEUED` are refused). There is no `FAILED → QUEUED`: a retry after
failure is a new run.

| Status | Set when |
|---|---|
| `QUEUED` | The run row is created. The job is dispatched after the transaction commits |
| `RUNNING` | A worker claimed it (`started_at`). Transient-error retries keep the run `RUNNING`; attempts are recorded in `metadata.attempts` |
| `SUCCEEDED` | A verified analyzer result was persisted with it, in one transaction |
| `FAILED` | A non-retryable error, attempts exhausted, the job was killed, a dispatch failure, or the stale sweeper |
| `CANCELLED` | The run was cancelled before finishing (no API yet) |

### Failure model

`failure_code` is machine-readable; the API shows it with a fixed message
(`AnalysisFailure::message()`), never analyzer output, exception text,
URLs or stack traces.

| Code | Meaning |
|---|---|
| `ANALYZER_UNAVAILABLE` | Connection failed, analyzer busy, unsigned 429/502/503/504, analyzer `INTERNAL_ERROR`/`RUN_IN_PROGRESS`, or attempts exhausted |
| `ANALYZER_TIMEOUT` | Laravel's HTTP timeout, or the worker killed the job |
| `ANALYZER_AUTH_FAILED` | The analyzer rejected the request signature, or its response signature did not verify |
| `ANALYZER_INVALID_RESPONSE` | Unsigned non-error response, malformed JSON, a malformed error envelope, or an unknown error code |
| `ANALYZER_RESULT_INVALID` | Schema violation, or run ID / request ID / result type / contract major mismatch |
| `ANALYZER_RESULT_HASH_MISMATCH` | `result_hash` does not match the recomputed canonical hash |
| `SOURCE_UNAVAILABLE` | The pre-signed URL could not be made, or the analyzer could not fetch or was not allowed to fetch the object |
| `SOURCE_URL_EXPIRED` | The URL expired on every attempt |
| `DISPATCH_FAILED` | The job could not be queued |
| `ANALYSIS_STALE` | Stuck in `QUEUED` or `RUNNING` (stale sweeper) |
| `ANALYSIS_FAILED` | A request Laravel built wrongly (`INVALID_REQUEST`, `RUN_CONFLICT`, …) or an unexpected error |
| `SOURCE_TOO_LARGE`, `TOO_MANY_FILES`, `INVALID_ARCHIVE`, `NO_SUPPORTED_FILES`, `SOURCE_CHECKSUM_MISMATCH`, `ANALYSIS_TIMEOUT` | The analyzer's verdict on the source, passed through |

### Retry matrix

Bounded: `ANALYZER_MAX_ATTEMPTS` = 3 attempts per run (first try included),
backoff 30 s then 120 s; an analyzer `Retry-After` is honoured if longer
(capped at 600 s); an expired source URL is retried after 1 s with a fresh
URL. The decision is Laravel's table (`AnalyzerErrorMap`, `AnalyzerClient`),
not the analyzer's `retryable` flag, so a misbehaving analyzer cannot force
retries.

| Failure | Retry? | Run failure code |
|---|---|---|
| Connection refused / DNS / reset | yes | `ANALYZER_UNAVAILABLE` |
| Laravel HTTP timeout (300 s) | yes | `ANALYZER_TIMEOUT` |
| Unsigned 429, 502, 503, 504 (proxy, overload) | yes | `ANALYZER_UNAVAILABLE` |
| `503 ANALYZER_BUSY`, `500 INTERNAL_ERROR`, `409 RUN_IN_PROGRESS` | yes | `ANALYZER_UNAVAILABLE` |
| `502 SOURCE_FETCH_FAILED` | yes | `SOURCE_UNAVAILABLE` |
| `422 SOURCE_URL_EXPIRED` | yes, with a fresh URL | `SOURCE_URL_EXPIRED` |
| Response signature invalid, missing or stale | **no** | `ANALYZER_AUTH_FAILED` |
| `401` (our signature rejected), `STALE_TIMESTAMP`, `REPLAY_DETECTED` | no | `ANALYZER_AUTH_FAILED` |
| Malformed JSON, unsigned 200/500, unknown code | no | `ANALYZER_INVALID_RESPONSE` |
| Schema violation, wrong run ID / request ID / result type | no | `ANALYZER_RESULT_INVALID` |
| `result_hash` mismatch | no | `ANALYZER_RESULT_HASH_MISMATCH` |
| `400`, `404`, `405`, `422 INVALID_REQUEST`, `409 RUN_CONFLICT` | no | `ANALYSIS_FAILED` |
| `422 SOURCE_HOST_NOT_ALLOWED` | no | `SOURCE_UNAVAILABLE` |
| `413`/`422` source verdicts (`INVALID_ARCHIVE`, …) | no | passed through |
| `504 ANALYSIS_TIMEOUT` (analyzer's own hard limit; deterministic input) | no | `ANALYSIS_TIMEOUT` |

A parse error inside a successful static-analysis result is part of the
result (a `PARSE_ERROR` file), not a failure.

### Timeout hierarchy

Inner limits always fire first (ADR-005), validated at boot by
`ConfigurationValidator`:

| Limit | Default | Variable |
|---|---|---|
| Analyzer hard limit per request | 240 s | `ANALYZER_HARD_TIMEOUT_SECONDS` |
| Laravel HTTP timeout (connect 5 s) | 300 s | `ANALYZER_TIMEOUT_SECONDS`, `ANALYZER_CONNECT_TIMEOUT_SECONDS` |
| Job timeout (worker kills the attempt) | 330 s | `ANALYSIS_JOB_TIMEOUT_SECONDS` |
| `retry_after` of the `analysis` queue connection | 360 s | `ANALYSIS_QUEUE_RETRY_AFTER` |
| Lease of a claimed run | job timeout + 30 s (+ backoff while waiting) | — |
| Stale `RUNNING` run | 1500 s without an update | `ANALYSIS_STALE_AFTER_SECONDS` |
| Stale `QUEUED` run | 24 h | `ANALYSIS_QUEUED_STALE_AFTER_SECONDS` |
| Pre-signed source URL | 900 s, a new one per attempt | `SOURCE_URL_TTL_SECONDS` |

The `analysis` connection has its own `retry_after` because the default
Redis connection's 90 s would let Redis hand a still-running analysis to a
second worker.

### Queue workers

| Item | Value |
|---|---|
| Connection / queue | `analysis` / `analysis` (Redis; separate from `default`) |
| Development | Compose service `queue`: `php artisan queue:listen analysis --queue=analysis --timeout=330 --sleep=3 --tries=0` (reloads code per job) |
| Production | `php artisan queue:work analysis --queue=analysis --timeout=330 --sleep=3 --tries=0`, several processes sized to the analyzer's `ANALYZER_MAX_CONCURRENCY` |
| Attempts | The job's own bound (`$tries` = `ANALYZER_MAX_ATTEMPTS`; the run's attempt count is authoritative) |
| Scheduler | Compose service `scheduler`: `php artisan schedule:work` (runs `analysis:fail-stale` every five minutes) |

### Stale runs

`analysis:fail-stale` marks runs `FAILED` with `ANALYSIS_STALE` when they
have been `RUNNING` without an update for 25 minutes (longer than three
attempts with their backoff) or `QUEUED` for 24 hours (a lost job). This
frees the logical analysis for a new run.

## Observability

- An `X-Request-ID` is generated per public request and stored in the new
  run's `metadata.request_id`. Each worker attempt has its own request ID,
  which is sent to the analyzer, checked in its response and stored in
  `metadata.attempts`. The run ID is the durable identity (the analyzer's
  `analysis_run_id` and `Idempotency-Key`).
- Lifecycle log events: `analysis.queued`, `analysis.started`,
  `analysis.retrying`, `analysis.completed`, `analysis.failed`,
  `analysis.result_ignored`, `analysis.duplicate_job_skipped`, and after
  scoring `dna.scored` or `dna.scoring_failed` (with `scoring_version`), with
  `analysis_run_id`, `project_id`, `source_snapshot_id`, `result_type`,
  `attempt`, `request_id`, `duration_ms`, `status`, `error_code`,
  `http_status`, `analyzer_code` and `result_hash` (IDs and codes only).
- Run timestamps (`created_at`, `started_at`, `completed_at`) support
  duration and queue-latency metrics.
- **Never logged:** source code, file contents, archive entry names, secret
  values, pre-signed URLs, HMAC secrets or signatures, session cookies, or
  tokens.
- Uploads log (Phase 07) `Source snapshot created.` with project, snapshot
  and user IDs, version, size and file count; `Source upload rejected.` with
  the error code and a fixed reason identifier; and storage failures with
  IDs and the exception class only.

## Data classification

| Data | Where | Sensitivity |
|---|---|---|
| Source archives | Object storage (private) | **High.** Customer IP, may contain secrets |
| Extracted source | Analyzer ephemeral temp directory | **High.** Deleted at the end of the request |
| IR | Analyzer memory only (v1) | Medium. Identifier names and paths |
| Metrics, features, scores | PostgreSQL | Medium |
| Secret findings | PostgreSQL | Medium. Locations only, never values |
| AI inputs (Phase 15) | Sent to the LLM provider | Derived metrics only; no source, no secrets |
