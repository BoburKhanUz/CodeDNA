# ADR-005: Laravel ↔ Analyzer Communication

- **Status:** Accepted
- **Date:** 2026-10-05
- **Related:** [Internal analyzer contract](../api/internal-analyzer-contract.md), [Data flow](../architecture/data-flow.md), [ADR-003](ADR-003-storage.md)

## Context

Laravel owns analysis orchestration and persistence. The Python analyzer owns
parsing, metrics and scoring. Large repositories must not be analyzed inside
a user's HTTP request. Failures must be observable and recoverable, and the
analyzer must never be reachable from the public internet.

## Decision

1. **Laravel queue workers orchestrate analysis.** A user request creates an
   analysis run and dispatches a Laravel job (`RunAnalysisJob`; implemented
   as `AnalyzeSourceSnapshot` in Phase 10) onto Redis.
   **Only Laravel reads and writes that queue.** Python does not consume
   Laravel queues and does not share Redis queue semantics.
2. **The worker calls the analyzer over an internal HTTP API**
   (`POST /internal/v1/analyze`). In the MVP the call is synchronous: the
   worker waits for the result, bounded by strict timeouts.
3. **The analyzer is stateless and side-effect free.** It has no database and
   no queue access. It writes nothing permanent, only an ephemeral working
   directory. **Laravel persists all results.**
4. **Network isolation.** The analyzer only listens on a private network that
   Laravel workers can reach. It has no public route, is not proxied by the
   public Nginx, and publishes no host port in production.
5. **HMAC request signing** (HMAC-SHA256 over a canonical request string with a
   timestamp, using a shared secret). Responses are signed the same way and
   verified by Laravel. Requests outside a ±300 s window are rejected.
   Secret rotation works through a current and a previous secret, both
   accepted during rotation. Signing is on top of network isolation, not a
   replacement for it.
6. **Reliability requirements** (detailed in the
   [contract](../api/internal-analyzer-contract.md)):
   - **Timeouts** are layered so that inner limits always fire first:
     analyzer hard limit (240 s) < Laravel HTTP timeout (300 s) < job
     timeout (330 s) < queue `retry_after` (360 s).
   - **Retry policy:** at most 3 attempts per run, with backoff of 30 s and
     then 120 s, and **only** for errors the analyzer marks `retryable: true`
     or for transport failures. Non-retryable errors fail the run
     immediately.
   - **Idempotency:** the `analysis_run_id` is sent as the `Idempotency-Key`.
     The analyzer rejects a concurrent duplicate with `409 RUN_IN_PROGRESS`.
     Laravel persists results idempotently: a run that is already in a
     terminal state ignores later results.
   - **Correlation:** every request carries an `X-Request-ID`. It is logged by
     both services, echoed in responses and errors, and stored on the run.
   - **Structured errors:** `{ "error": { "code", "message", "retryable",
     "request_id", "details" } }` with stable, documented error codes.
   - **API versioning:** major version in the path (`/internal/v1`), and
     `contract_version` in request and response bodies.
   - **Status tracking:** run status (`QUEUED → RUNNING → SUCCEEDED |
     FAILED | CANCELLED`; see docs/architecture/data-model.md), failure code
     and timestamps are persisted by Laravel, and attempts go into the run's
     metadata. A scheduled sweeper fails runs stuck in `RUNNING` past the
     job timeout.

## Consequences

- The design is simple and easy to observe: one HTTP call per attempt, with
  one place (Laravel) that owns state.
- The PHP worker is occupied for the duration of an analysis. Worker
  concurrency must be sized for this, and the analyzer limits its own
  concurrency (`503 ANALYZER_BUSY`, which is retryable).
- **Evolution path:** if analyses outgrow synchronous calls, switch to an
  asynchronous contract (`202 Accepted` plus a signed callback to Laravel, or
  polling) as `/internal/v2`, without changing the public API or the
  run-state model.

## Alternatives considered

- **Shared Redis queue consumed by a Python worker:** rejected for now. It
  couples two runtimes to one queue's serialization format and retry
  semantics, and splits state ownership.
- **gRPC:** rejected as unnecessary complexity for one internal endpoint.
- **Mutual TLS instead of HMAC:** stronger transport identity, but more
  operational overhead. It may be added in Phase 21 (security hardening) on
  top of HMAC.
