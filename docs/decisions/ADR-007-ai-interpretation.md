# ADR-007: AI Interpretation — Non-Authoritative, Provider-Neutral, Evidence-Bound

- **Status:** Accepted
- **Date:** 2026-10-06
- **Related:** [ADR-004](ADR-004-dna-scoring.md), [AI assessment v1](../architecture/ai-assessment-v1.md), [Skill gap v1](../architecture/skill-gap-v1.md)

## Context

By Phase 14, CodeDNA produces deterministic, versioned and fingerprinted
results:

- DNA snapshots;
- competency matrices;
- skill gaps against a target profile.

They are precise but dense, so users benefit from a plain-language
explanation. Language models write such explanations well. They also:

- invent facts;
- produce confident numbers;
- judge people;
- follow instructions hidden in their input;
- vary between calls and providers;
- cost money per call.

ADR-004 makes reproducibility and evidence the product's foundation.
Whatever AI adds must not weaken it.

## Decision

### 1. AI is non-authoritative

The AI explains stored deterministic results. It never calculates,
modifies, overrides or stores:

- a score, level, gap, priority or target;
- a threshold, metric, hash, data quality or version.

Its output has no numeric or ranking fields at all, and nothing reads it
back into any calculation. When AI text conflicts with deterministic data,
the deterministic data wins, and the UI says so.

### 2. A bounded, purpose-built input from persisted snapshots

The input is built from the stored skill gap, competency and DNA snapshots
through an allowlist:

- enum-checked identifiers;
- decimal strings and counts;
- server-owned descriptions.

It contains no source code, file names, finding messages, project or
profile text, storage references, credentials or raw analyzer output. It is
canonical JSON with a schema version and a SHA-256 fingerprint covering its
lineage. Snapshots whose specification fingerprints do not match their
versions are rejected.

### 3. A server-owned, versioned prompt; evidence is data

- The prompt is fixed per version and fingerprinted. There is no user prompt.
- The variable part is canonical JSON between explicit delimiters, declared
  to be data that must never be followed as instructions.
- Every source-derived string is untrusted. Because none is copied, an
  injection has nothing to ride on.

### 4. Strict output, verified locally

The response must be one JSON object of the closed `assessment/v1` schema,
and every claim must cite evidence ids from the input. The local validator
checks:

- size, the schema and item caps;
- references;
- forbidden fields;
- content rules: no scores, seniority, person judgments, links, courses,
  history claims, prompt leaks, unsupported topics, invented numbers, or
  strengths built on material gaps.

The checks run for every provider. A response either passes completely or
is discarded: there is no repair, no retry of invalid output and no fallback
text.

### 5. Provider neutrality without an SDK

The domain depends on one interface:

```text
AiProvider::generateAssessment(input, prompt) -> raw response
```

It has normalized, retry-classified errors. Version 1 ships:

- an adapter for the OpenAI-compatible Chat Completions API, which covers
  hosted services and local servers;
- a deterministic fake provider for development (refused in production).

The provider, model, URL and key come only from the environment. The key is
never stored, logged or exposed.

### 6. Asynchronous, idempotent, immutable

- **Request:** a POST records a QUEUED assessment and dispatches a job. No
  provider call happens during an HTTP request.
- **Identity:** (project, input fingerprint, version, prompt fingerprint,
  provider, model).
- **One active assessment per identity:** enforced by a project lock and a
  partial unique index. Job leases prevent duplicate provider calls.
- **Retries:** only transient failures (timeouts, 429, 5xx) are retried,
  with a bounded number of attempts.
- **Immutable records:** they carry full lineage, versions and fingerprints.
  A database trigger refuses changes to terminal records. Regeneration
  creates a new record.
- **Isolation:** an AI failure never invalidates any deterministic result.

### 7. On request only, disabled by default

AI is off unless `AI_ENABLED=true`. When enabled, an assessment is generated
only when the project's owner asks for one, never automatically after an
analysis, and the request is rate-limited. This keeps cost and data sharing
under the operator's and the user's control.

## Consequences

**Positive:**

- Explanations without any change to the meaning or reproducibility of
  scores.
- Every AI statement is traceable to stored evidence.
- Injection and hallucination are contained by construction and by
  validation, not by trust in the model.
- Providers can be switched by configuration, including to local models.
  Tests need no paid provider.

**Negative and trade-offs:**

- **Strict rules reject some acceptable text.** A response that mentions a
  number not present in the evidence, or a "test" outside limitations,
  fails as a whole. The rules favour safety over availability; the prompt
  tells the model about them to keep failures rare.
- **Not reproducible across calls.** AI text is not byte-reproducible even at
  temperature 0. That is acceptable because it is non-authoritative, and the
  stored record, not a recomputation, is the reference.
- **Small surface area.** One adapter (OpenAI-compatible) and no
  streaming, cancellation or cost accounting in version 1.
- **The pattern rules are heuristics.** They catch the categories above
  reliably, but cannot prove a free-text sentence is fully supported by the
  evidence. Every claim therefore cites evidence the user can inspect, and
  the page states that the text can be incomplete or wrong.

## Alternatives considered

- **Let the AI score or rank developers.** This was rejected: it would make
  results non-deterministic, unexplainable and potentially discriminatory.
- **Send source code or findings for a richer explanation.** This was
  rejected for privacy, cost and injection risk; the deterministic
  snapshots already summarize what is measured.
- **A vendor SDK in the domain.** This was rejected for lock-in, harder
  testing and secret handling spread across the code.
- **Generate after every analysis.** This was rejected: it costs money and
  shares data without the user's request. It may become an opt-in setting
  later.
- **Repair or retry invalid output.** This was rejected: a repaired response
  is no longer what the model said, and retrying an invalid response
  multiplies cost for little gain.
