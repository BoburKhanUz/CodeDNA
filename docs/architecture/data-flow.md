# Data Flow — Analysis Pipeline

This document describes how one analysis moves through the system. It is the
reference for Phases 07 (upload), 10 (queue and pipeline) and 11 (scoring).

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
   │───────────────►│───────────────►│ validate type/size │                  │                 │                    │
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
- **Never logged:** source code, file contents, secret values, pre-signed
  URLs, HMAC secrets or signatures, session cookies, or tokens.

## Data classification

| Data | Where | Sensitivity |
|---|---|---|
| Source archives | Object storage (private) | **High.** Customer IP, may contain secrets |
| Extracted source | Analyzer ephemeral temp directory | **High.** Deleted at the end of the request |
| IR | Analyzer memory only (v1) | Medium. Identifier names and paths |
| Metrics, features, scores | PostgreSQL | Medium |
| Secret findings | PostgreSQL | Medium. Locations only, never values |
| AI inputs (Phase 15) | Sent to the LLM provider | Derived metrics only; no source, no secrets |
