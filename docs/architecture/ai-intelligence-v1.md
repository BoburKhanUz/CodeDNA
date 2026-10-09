# Local AI Intelligence, Version 1 (insight version 1.0.0)

Phase 29 adds a locally hosted AI layer that **explains** stored deterministic
results. It never produces them. Scores, levels, gaps, roadmap steps, test
verdicts and growth directions come only from the deterministic engines
([DNA scoring](dna-scoring-v1.md), [competencies](competency-matrix-v1.md),
[skill gaps](skill-gap-v1.md), [roadmaps](learning-roadmap-v1.md),
[challenges](coding-challenges-v1.md), [growth](growth-tracking-v1.md)); the
AI reads a bounded, allowlisted copy of them and writes plain-text
explanations that cite that evidence. Where an explanation and the data
disagree, the data is right; the explanation is rejected if the disagreement
is detectable (see [Validation](#validation)).

Operating the runtime (installing Ollama, choosing a model for your
hardware, health levels, troubleshooting) is in
[Local AI operations](../operations/local-ai.md).

Design rules:

- **Local by default.** The default provider is an Ollama server on the
  private Docker network. No external AI API or credential is required, and
  nothing ever falls back to another provider: if the configured runtime is
  unavailable, the feature reports that and every deterministic page stays
  complete without it.
- **Evidence-grounded.** The model receives only facts the server selected,
  each with a stable evidence ID. Every statement it returns must cite IDs
  from that input, and the server checks the claims against the facts.
- **Non-authoritative and opt-in.** AI runs only when a user asks for an
  explanation, on its own queue and worker, and its output is stored apart
  from the deterministic tables, which it can never write.
- **No code, no tools.** No source code, file paths, test inputs, expected
  or observed values, or user-written text is sent. The model has no tools,
  no network access through CodeDNA and no way to execute anything.

## Contents

- [AI gateway](#ai-gateway)
- [Insights](#insights)
- [Evidence](#evidence)
- [Contract](#contract)
- [Validation](#validation)
- [Lifecycle](#lifecycle)
- [Storage](#storage)
- [Configuration](#configuration)
- [Testing](#testing)
- [Deferred](#deferred)

## AI gateway

Every model call, for [AI assessments](ai-assessment-v1.md) and for insights,
goes through one gateway (`App\Services\Ai\AiGateway`) and one client
interface (`App\Services\Ai\ModelClient`):

| Client | `AI_PROVIDER` | Protocol |
| --- | --- | --- |
| `OllamaClient` (default) | `ollama` | Ollama's native `POST /api/chat`, non-streaming; health from `/api/version` and `/api/tags` |
| `OpenAiCompatibleProvider` | `openai_compatible` | Chat Completions (`POST /chat/completions`), for llama.cpp server, vLLM, LM Studio and similar; health from `/models` |
| `FakeModelClient` / `FakeAiProvider` | `fake` | Deterministic templates built from the evidence; refused in production |

The gateway, in order:

1. **Context budget.** The prompt is estimated at one token per three bytes
   plus a fixed overhead; estimated prompt tokens plus `AI_MAX_OUTPUT_TOKENS`
   must fit `AI_CONTEXT_TOKENS`. A request that does not fit is refused
   before anything is sent (`INPUT_TOO_LARGE`), never truncated. The request
   endpoint applies the same check, so the user is told at once.
2. **Concurrency.** A Redis semaphore (`codedna:ai-slots`) admits at most
   `AI_MAX_CONCURRENCY` generations across every worker. A job that cannot
   get a slot within `AI_SLOT_WAIT_SECONDS` is released and retried later
   (`slots_busy`, retryable); it never queues work inside the runtime.
3. **The call.** Bounded connect and read timeouts (`AI_TIMEOUT_SECONDS`
   applies to the whole read, including slow CPU generation), a bounded
   response envelope, temperature 0 and a fixed seed, the JSON schema as
   structured output, and `num_ctx`/`num_predict` set from the budget.
   Truncated output (`done_reason: length`), runtime errors and a missing
   model are distinct failures.
4. **Accounting.** Per-outcome counters, total duration and in-flight count
   in the cache (`AiMetrics`, shown by `php artisan ai:status`), and one
   `ai.completion` log line per call: task, provider, model, duration,
   status, error code and a short reason. Never a prompt, response, key or
   URL.

Retries (timeouts, 429, 5xx, busy slots) belong to the job, not the gateway:
at most `AI_MAX_ATTEMPTS` calls per insight with the configured backoff.
Invalid output is never retried.

Queue isolation: insight and assessment jobs run on the `assessment` queue,
which only the `ai-worker` service consumes. The main `queue` worker
consumes `analysis,challenge,github`, so a slow model never delays analysis,
challenge evaluation or imports.

## Insights

| Kind | Subject | Requested from | Explains |
| --- | --- | --- | --- |
| `GROWTH_INTERPRETATION` | a `COMPARED` growth snapshot | Growth page | What changed between two analyses, in the terms of the stored observations |
| `ROADMAP_GUIDANCE` | an `ACTIVE` learning roadmap | Learning Roadmap page | Why the focus was chosen and which **available** steps to take next |
| `CHALLENGE_FEEDBACK` | a `PASSED` or `FAILED` challenge submission | Challenge page | What the evaluation result means and what to practise |

CodeDNA interpretation and skill-gap explanation remain the Phase 15
[AI assessment](ai-assessment-v1.md), which now also runs through the
gateway and therefore uses the local runtime by default.

Insights never assign or change a score, level, gap, priority, step state,
verdict or growth direction, never judge the developer as a person, and never
claim seniority.

## Evidence

`InsightInputBuilder` builds the input from stored rows only, through one
builder per kind (`App\Services\Insights\Evidence\*`). Every item has an ID
of the form `type:key` (`^[a-z_]{1,24}:[A-Za-z0-9_.:-]{1,96}$`), a kind, a
catalog label and description, and typed facts.

| Kind | Evidence items | Never included |
| --- | --- | --- |
| Growth | `growth:summary` (counts by status), `growth:rules` (rules version and thresholds), up to 64 `obs:TYPE:KEY` observations (status, before/after values and levels, delta, evidence quality) | Source code, file names, project names, user names |
| Roadmap | `roadmap:summary`, `focus:*` (current and target level, gap, priority), `track:*`, `step:*` with state `COMPLETED`, `AVAILABLE` or `LOCKED` | Free text from users, completion timestamps beyond counts |
| Challenge | `challenge:definition` (catalog text), `result:verdict`, `criterion:*`, `rule:*` (limit, observed, status), `case:*` (visibility, status, error type) | The submitted source, test arguments, expected and observed values, tracebacks, hidden test details |

Every builder refuses evidence it cannot represent faithfully (wrong status,
a growth snapshot measured under another rules fingerprint, too many items)
with `INSIGHT_EVIDENCE_UNAVAILABLE` instead of sending a partial picture. The
canonical input JSON is fingerprinted; the fingerprint is part of the
insight's identity.

## Contract

`InsightSpecification` owns everything the model sees: a fixed system prompt
per kind, the user message framing and the output schema. Fingerprints of
the prompt and of the whole specification are stored with every insight, so
a changed prompt can never be mixed with old results.

The user message wraps the evidence between
`<<<BEGIN_UNTRUSTED_EVIDENCE_JSON>>>` and `<<<END_UNTRUSTED_EVIDENCE_JSON>>>`;
the system prompt says that the content between them is data, not
instructions. Catalog text is the only prose in the evidence, and it is
server-owned.

The output schema is `insight/v1`:

```json
{
  "schema_version": "insight/v1",
  "summary": { "text": "…", "evidence_refs": ["…"] },
  "points": [{ "title": "…", "description": "…", "evidence_refs": ["…"] }],
  "next_steps": [{ "title": "…", "description": "…", "evidence_refs": ["…"] }],
  "limitations": [{ "description": "…", "evidence_refs": ["…"] }]
}
```

1–6 points, 0–4 next steps, 1–4 limitations, 1–8 references per statement.
The schema sent to the model is narrowed per request: `evidence_refs` items
are an enum of the input's IDs, and for roadmaps next-step references are an
enum of the `AVAILABLE` steps (no next steps at all if none is available).
Narrowing helps small models; it is not trusted, and the server validates
everything again.

## Validation

`InsightResponseValidator` accepts an output only if every step passes; one
failure discards the whole response (`INVALID_OUTPUT`, with a reason code in
the metadata and logs, never the text):

1. Size limit, JSON extraction, no forbidden keys (`score`, `level`,
   `priority`, `verdict` and similar).
2. Schema and limits: required fields, counts, lengths, reference format.
3. Every reference names an evidence item in the input.
4. Forbidden text in any field: numeric scores or score statements,
   seniority, judgments of the person, external links or resources, prompt
   or delimiter leaks, markup and code.
5. Control characters.
6. Unsupported numbers: every number in the text must appear in the cited
   evidence (evidence IDs such as `AC2` or `h1` are removed first).
7. Kind rules:
   - Growth: words of improvement or regression in the summary and points
     must cite observations whose status says the same (negations are
     understood); topics the evidence does not measure (tests, security,
     performance, documentation) may only appear as limitations.
   - Roadmap: next steps must cite `AVAILABLE` steps; the same topic rule.
   - Challenge: pass or fail wording must match the cited statuses.
   - Every kind except growth: no claims about history ("has improved").

The validator is deliberately strict. A small model will sometimes fail it.
That is the intended outcome: an explanation that cannot be checked is not
shown.

## Lifecycle

```text
POST /insights → RequestInsight → QUEUED ── GenerateInsight (ai-worker) ──► RUNNING ──► SUCCEEDED
                      │                                    │                      └────► FAILED
                      └── same identity: returns existing  └── retryable: back to QUEUED with backoff
```

Request (`RequestInsight`), synchronous and model-free:

1. AI enabled, plan includes AI (`Feature::AiAssessment`), runtime
   available (cached health check, otherwise `AI_UNAVAILABLE`, 503).
2. Project locked; archived projects are read-only (`PROJECT_ARCHIVED`).
3. Subject found in this project (otherwise 404); evidence built; size and
   context budget checked.
4. Existing insight with the same identity (kind, subject, versions,
   fingerprints) and not failed → returned with `Idempotent-Replayed: true`.
5. Otherwise created, one `ai_insight` quota unit consumed, job dispatched.

Job (`GenerateInsight`):

- Claims the insight with a lease and claim token; a duplicate or late job
  does nothing.
- Rebuilds the evidence and recomputes every fingerprint; a change since the
  request fails the insight (`EVIDENCE_CHANGED`), as does a changed prompt,
  provider or model (`configuration_changed`).
- Calls the gateway, validates, stores the output and metadata (tokens when
  the runtime reports them, duration).
- Retries timeouts, rate limits, unavailability and busy slots up to
  `AI_MAX_ATTEMPTS` with backoff `[20, 60]` seconds.
- On failure, the quota unit is refunded.

`php artisan insight:fail-stale` (scheduled) fails insights whose lease
expired or that stayed queued too long (`INSIGHT_STALE`), exactly like
`assessment:fail-stale`.

## Storage

Table `ai_insights` (migration `2026_10_21_000001_create_ai_insights_table`,
with `down()`):

- One subject column per kind (`growth_snapshot_id`, `roadmap_snapshot_id`,
  `challenge_submission_id`) with composite foreign keys on `(id,
  project_id, user_id)`, so an insight can never point into another project
  or another owner's data; a CHECK ties the subject column to the kind.
  `requested_by` records who asked (a project member may differ from the
  owner).
- Versions, fingerprints, provider, model, served model, status, lease and
  claim token, the canonical input, the validated output, metadata and
  failure code/message, with CHECKs that tie output and failure to status.
- A partial unique index (`ai_insights_identity_active_unique`) on
  project, kind, input fingerprint, insight version, prompt fingerprint,
  provider and model for non-failed insights, so concurrent requests cannot
  create duplicates. The input fingerprint covers the subject's evidence.
- A trigger makes `SUCCEEDED` and `FAILED` rows immutable.

The migration also adds a unique index on `challenge_submissions (id,
project_id, user_id)` for the composite foreign key. No deterministic table
is altered otherwise. Rolling back drops `ai_insights` and that index; no
deterministic data is touched.

## Configuration

All settings are environment variables (`backend/config/codedna.php`, `ai`
block), validated at boot by `ConfigurationValidator`. The main ones:

| Variable | Default | Meaning |
| --- | --- | --- |
| `AI_ENABLED` | `false` | Master switch for assessments and insights |
| `AI_PROVIDER` | `ollama` | `ollama`, `openai_compatible` or `fake` |
| `AI_MODEL` | `qwen2.5-coder:7b` | The model tag; it must already be pulled |
| `AI_BASE_URL` | `http://ollama:11434` | Runtime URL |
| `AI_ALLOW_REMOTE_ENDPOINT` | `false` | Required (with https) for a host outside the private network in production |
| `AI_TIMEOUT_SECONDS` / `AI_JOB_TIMEOUT_SECONDS` | `120` / `180` | Read timeout per call; job timeout must exceed timeout plus slot wait |
| `AI_CONTEXT_TOKENS` | `8192` | Context window (`num_ctx`) |
| `AI_MAX_CONCURRENCY` / `AI_SLOT_WAIT_SECONDS` | `1` / `10` | Generations at once across workers, and how long a job waits for a slot |
| `AI_KEEP_ALIVE` | `5m` | How long Ollama keeps the model loaded |
| `AI_HEALTH_CACHE_SECONDS` | `60` | How long a health check result is cached |
| `CODEDNA_INSIGHTS_VERSION` | `1.0.0` | Insight specification version |

Upgrade notes:

- The defaults changed: `AI_PROVIDER` is now `ollama`, `AI_BASE_URL`
  `http://ollama:11434`, `AI_MODEL` `qwen2.5-coder:7b` and
  `AI_TIMEOUT_SECONDS` 120 (job timeout 180). An installation that uses
  `AI_PROVIDER=openai_compatible` must set `AI_BASE_URL` and `AI_MODEL`
  explicitly if it relied on the previous defaults. `AI_ENABLED` still
  defaults to `false`, so nothing is generated until AI is enabled.
- In production, an endpoint outside the private network (a host with a dot
  or an IP address) now needs `AI_ALLOW_REMOTE_ENDPOINT=true` as well as
  https. This is deliberate: it is where private evidence would leave the
  installation.

## Testing

- Unit and feature tests use `FakeModelClient` and a scripted client
  (timeouts, rate limits, truncation, injected text, contradictory output)
  and `Http::fake` for the Ollama and OpenAI-compatible protocols.
- Score invariance: generating, failing and retrying insights leaves every
  row of every deterministic table unchanged.
- Prompt injection: evidence containing instructions, and outputs that
  follow them, are rejected.
- Real-model checks are manual (`php artisan ai:smoke`, and requesting an
  insight with a pulled model); CI never downloads a model.

## Deferred

- Streaming responses to the browser.
- Explanations for the DNA, competency and skill-gap pages beyond the
  existing AI assessment.
- Per-organization model selection and usage dashboards.
- Automatic model evaluation suites across model versions.
