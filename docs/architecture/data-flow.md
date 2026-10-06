# Data Flow — Analysis Pipeline

This document describes how source arrives and how one analysis moves
through the system. Source upload is implemented (Phase 07); the analysis
pipeline (Phase 10) and scoring (Phase 11) are not yet.

Related: [ADR-003](../decisions/ADR-003-storage.md) (storage),
[ADR-005](../decisions/ADR-005-service-communication.md) (communication),
[internal analyzer contract](../api/internal-analyzer-contract.md).

## Domain terms

The entities are implemented in Phase 05; see [data-model.md](data-model.md).

| Term | Meaning |
|---|---|
| **Project** | A user-owned project with one source origin (`UPLOAD`, or `REPOSITORY` with an HTTPS URL) |
| **Source snapshot** | An immutable reference to an archive in object storage (key, SHA-256, size, per-project version) |
| **Analysis run** | One execution of the pipeline against one snapshot. Re-analysis (after an analyzer upgrade or a failure) creates a **new run**; earlier runs are kept. Job retries for transient errors happen **within** a run. |
| **DNA snapshot** | The immutable result of one `SUCCEEDED` run (at most one per run) |

## End-to-end sequence (MVP)

```text
Browser (UI)     Nginx          Laravel API         Object storage      Redis queue     Laravel worker        Analyzer
   │  upload ZIP    │                │                    │                  │                 │                    │
   │───────────────►│───────────────►│ inspect ZIP, hash  │                  │                 │                    │
   │                │                │──── put object ───►│                  │                 │                    │
   │                │                │ record source snapshot (v1, v2, …)    │                 │                    │
   │  start analysis│                │                    │                  │                 │                    │
   │───────────────►│───────────────►│ create analysis run (QUEUED)          │                 │                    │
   │                │                │──── dispatch RunAnalysisJob (after commit) ────►│       │                    │
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
`make verify` exercises this path from the backend container. What is
missing is the Laravel side: the analysis run, the queued job and the client
come in Phase 10, and nothing a static-analysis result contains is persisted
yet.

## Run state machine

```text
            worker starts the job
 QUEUED ──────────────────────────► RUNNING ──── verified result ────► SUCCEEDED
    │                                  │
    │                                  ├── non-retryable error / attempts exhausted / stale ──► FAILED
    │                                  └── cancelled ──► CANCELLED
    ├── dispatch failure / stale ──► FAILED
    └── cancelled ──► CANCELLED
```

Transitions are enforced by `AnalysisRunStatus::canTransitionTo()` and the
`AnalysisRun` model ([data-model.md](data-model.md#lifecycle)).

| Status | Set when |
|---|---|
| `QUEUED` | The run row is created. The job is dispatched after the transaction commits |
| `RUNNING` | A worker started processing (`started_at`). Transient-error retries keep the run `RUNNING`; attempts are recorded in `metadata` |
| `SUCCEEDED` | A verified analyzer result was persisted, together with its DNA snapshot, in one DB transaction |
| `FAILED` | A non-retryable error, attempts exhausted, a dispatch failure, or the stale sweeper |
| `CANCELLED` | The run was cancelled before finishing |

- `SUCCEEDED`, `FAILED` and `CANCELLED` are **terminal and immutable**.
- A project's displayed analysis status is the status of its **latest run**.
- Failed runs store a `failure_code` (from the contract's error codes plus
  `ANALYZER_UNREACHABLE`, `ANALYSIS_STALE` and `DISPATCH_FAILED`), a
  user-safe `failure_message` and `failed_at`. Request IDs and attempt
  counts go into `metadata`.
- **Recovery:** a new run for the same snapshot (Phase 10 endpoint).
  Earlier runs are kept.
- **Stale sweeper:** a scheduled Laravel command marks runs as `FAILED`
  (`ANALYSIS_STALE`) if they have been `RUNNING` for longer than the job
  timeout plus a grace period. This covers crashed workers. It uses the
  partial index on active runs.

## Persistence of a result (one transaction)

1. Lock the run row. If it is already terminal, ignore the result and log it
   (idempotency).
2. Mark the run `SUCCEEDED` with its versions (`analyzer`, `ir`, `metrics`,
   `scoring`, `contract`) and `result_hash`.
3. Insert the immutable DNA snapshot (allowed only for a `SUCCEEDED` run).
4. Commit. Where metrics, features and findings (locations only) are
   persisted is decided in Phase 10: run `metadata` or dedicated tables,
   added only when needed.

## Timeouts and retries

These are defined in the [contract](../api/internal-analyzer-contract.md#7-timeouts-retries-and-limits).
In summary: analyzer 240 s < HTTP 300 s < job 330 s < `retry_after` 360 s,
with at most 3 attempts and backoff of 30 s and then 120 s, for retryable
errors only.

## Observability

- An `X-Request-ID` is generated per public request. Each worker attempt has
  its own request ID, which is sent to the analyzer and stored on the run.
- Structured JSON logs carry `request_id`, `analysis_id`, `analysis_run_id`,
  `attempt`, `status` and `failure_code`.
- Run timestamps (`queued_at`, `started_at`, `finished_at`) support duration
  and queue-latency metrics.
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
