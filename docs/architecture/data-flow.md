# Data Flow — Analysis Pipeline

This document describes how one analysis moves through the system. It is the
reference for Phases 07 (upload), 10 (queue and pipeline) and 11 (scoring).

Related: [ADR-003](../decisions/ADR-003-storage.md) (storage),
[ADR-005](../decisions/ADR-005-service-communication.md) (communication),
[internal analyzer contract](../api/internal-analyzer-contract.md).

## Domain terms

| Term | Meaning |
|---|---|
| **Project** | A user-owned container for one or more repositories |
| **Repository** | A source origin inside a project. Provider is `upload` in the MVP; `github`/`gitlab` later. |
| **Source snapshot** | An immutable archive of a repository at one point in time (object key, SHA-256, size, optional commit SHA) |
| **Analysis** | A user's request to analyze one source snapshot |
| **Analysis run** | One execution of the pipeline for an analysis. Re-running an analysis (for example after an analyzer upgrade or a failure) creates a **new run**. Job retries for transient errors happen **within** a run as attempts. |
| **DNA snapshot** | The immutable scored result of one completed run |

## End-to-end sequence (MVP)

```text
Browser (UI)     Nginx          Laravel API         Object storage      Redis queue     Laravel worker        Analyzer
   │  upload ZIP    │                │                    │                  │                 │                    │
   │───────────────►│───────────────►│ validate type/size │                  │                 │                    │
   │                │                │──── put object ───►│                  │                 │                    │
   │                │                │ create snapshot (sha256, size)        │                 │                    │
   │  start analysis│                │                    │                  │                 │                    │
   │───────────────►│───────────────►│ create analysis + run (pending)       │                 │                    │
   │                │                │──────── dispatch RunAnalysisJob ─────►│ run: queued     │                    │
   │                │◄── 202 {id} ───│                    │                  │                 │                    │
   │                │                │                    │                  │── job ─────────►│ run: processing    │
   │                │                │                    │◄── presign GET ─────────────────────│                    │
   │                │                │                    │                  │                 │── signed POST ────►│
   │                │                │                    │◄──────────────── fetch archive ──────────────────────────│
   │                │                │                    │                  │                 │                    │ analyze
   │                │                │                    │                  │                 │◄── signed result ──│
   │                │                │                    │                  │                 │ verify, persist    │
   │                │                │                    │                  │                 │ run: completed     │
   │  poll status   │                │                    │                  │                 │                    │
   │───────────────►│───────────────►│ status / result    │                  │                 │                    │
```

The browser runs the Next.js UI. In the single-origin topology (ADR-006), its
API calls go through Nginx straight to Laravel, not through the Next.js server.

## Run state machine

```text
             dispatch               worker picks up
 pending ───────────────► queued ───────────────────► processing ──── success ───► completed
    │                       ▲                             │
    │                       └──── retryable error ────────┤  (attempt < max)
    │                                                     │
    └── dispatch failure ──► failed ◄── non-retryable error / attempts exhausted / stale
```

| Status | Set when |
|---|---|
| `pending` | The run row is created, before the job is dispatched |
| `queued` | The job is on the queue, either the first time or waiting for a retry backoff |
| `processing` | A worker has started an attempt (`attempt` is incremented) |
| `completed` | A verified analyzer result has been persisted, together with its DNA snapshot, in one DB transaction |
| `failed` | A non-retryable error, attempts exhausted, a dispatch failure, or the stale sweeper |

- `completed` and `failed` are **terminal and immutable**.
- An analysis's displayed status is the status of its **latest run**.
- Failed runs store a `failure_code` (from the contract's error codes plus
  `ANALYZER_UNREACHABLE`, `ANALYSIS_STALE` and `DISPATCH_FAILED`), a
  user-safe `failure_message`, `request_id`, `attempts` and `failed_at`.
- **Recovery:** the user (or later an admin action) creates a new run for the
  same analysis via `POST /api/v1/analyses/{id}/runs`. Earlier runs are kept.
- **Stale sweeper:** a scheduled Laravel command marks runs as
  `failed (ANALYSIS_STALE)` if they have been `processing` for longer than
  the job timeout plus a grace period. This covers crashed workers.

## Persistence of a result (one transaction)

1. Lock the run row. If it is already terminal, ignore the result and log it
   (idempotency).
2. Store the versions (`analyzer`, `ir`, `metrics`, `scoring`, `contract`),
   the source summary, metrics, features, findings (locations only) and
   `result_hash`.
3. Insert the immutable DNA snapshot.
4. Mark the run `completed`.

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
