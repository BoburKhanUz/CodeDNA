# ADR-009: Learning Roadmap — A Deterministic Planning Layer, Generated on Request

- **Status:** Accepted
- **Date:** 2026-10-14
- **Related:**
  - [ADR-004](ADR-004-dna-scoring.md)
  - [ADR-007](ADR-007-ai-interpretation.md)
  - [ADR-008](ADR-008-coding-challenges.md)
  - [Learning roadmap v1](../architecture/learning-roadmap-v1.md)

## Context

Phase 14 tells a developer which measurable competencies have a gap
against a target. Phase 16 offers exercises for those gaps. What is
missing is a plan: what to work on first, and the concrete steps for it.

There are three risks:

- **The plan could feed back into the measurement.** If completing steps
  changed scores or closed gaps, CodeDNA would measure self-reported
  activity instead of code, and ADR-004's reproducibility would break.
- **Unstable or untraceable plans.** A plan that changes silently when data
  or rules change cannot be trusted or explained.
- **Generated content.** AI-written lessons would be non-deterministic and
  unverifiable, and ADR-007 limits AI to non-authoritative explanation.

## Decision

### 1. A planning layer that never touches assessment

A roadmap reads one stored skill gap snapshot and writes only roadmap
tables. Completing steps is self-reported learning progress. It never
changes a score, a level, a gap, a priority, a target or a snapshot, and
it never starts an analysis. Only a new analysis of new code can show
improvement. The API and the UI say this on every roadmap.

### 2. Server-owned, versioned content; deterministic, versioned rules

- **Catalog.** One track per measurable competency, with short practical
  steps. It is a versioned, fingerprinted catalog in the repository,
  validated at load. Clients never supply content.
- **Rules.** Ordering, actionable statuses and limits are a versioned,
  fingerprinted rules object.
- **Focus.** It reuses Phase 14's priority, which already caps HIGH on thin
  evidence, and adds the raw gap, evidence quality and key as tie-breakers.
  There is no new score.
- **Determinism.** Generation is a pure function of the snapshot, the
  catalog, the rules and the challenge catalog. It uses no AI, no clock and
  no randomness.

### 3. Immutable snapshots with one-way status

- **Copied content.** A roadmap copies its steps and stores its focus,
  versions, fingerprints and full lineage, so it stays readable and
  unchanged when the catalog or the skill gaps change.
- **One-way status.** Only the status moves, once: `ACTIVE` →
  `COMPLETED`, or `ACTIVE` → `SUPERSEDED`. A database trigger enforces it.
- **Insert-only progress.** Completions are insert-only rows.

### 4. Explicit, idempotent generation (not automatic after each analysis)

Generation runs on request (`POST .../roadmaps`, empty body). It is
idempotent per (skill gap snapshot, roadmap version, rules version), and a
newer roadmap supersedes the previous active one. This matches Phases 15
and 16, which act on stored results only when asked, and has three
benefits:

- **The pipeline stays unchanged.** The analysis job and Phases 10–14 are
  not touched, so a roadmap failure can never affect an analysis.
- **The developer decides.** The developer chooses when to replace a
  roadmap they are working through with one for a newer analysis. The old
  one, with its progress, stays readable.
- **No backfill.** Existing projects need no backfill: a roadmap is created
  when it is first wanted.

### 5. Challenges are referenced, never duplicated

A `CHALLENGE` step references the Phase 16 catalog by key and version. The
recommendation comes from the Phase 16 selector's pure function applied to
the gap, without history. Passing a challenge never closes a gap.

## Consequences

### Positive

- CodeDNA's measurements stay independent of learning activity.
- Every roadmap can be explained and reproduced: the stored focus says why
  each competency ranks where it does, and a test regenerates a stored
  roadmap to the same fingerprint.
- The roadmap is small: at most three tracks of at most eight steps.

### Negative

- A new analysis does not update the roadmap by itself. The page offers
  "Update roadmap" when a newer analysis exists.
- Completion is final in v1: there is no undo.
- The content is static text, with no exercises beyond Phase 16's
  challenges.

## Alternatives considered

| Alternative | Why it was rejected |
|---|---|
| Generate after every skill gap snapshot in the analysis job | It changes the pipeline, and supersedes plans without the developer's intent. |
| Recompute the roadmap on every read | Plans would change silently and could not be reproduced. |
| AI-generated roadmaps or lessons | They are non-deterministic and unverifiable (ADR-007). |
| Let completed steps or tracks raise scores or close gaps | This contradicts ADR-004 and measures activity instead of code. |
| Tracks for testing, security, architecture and similar competencies | CodeDNA does not measure them yet; a track without evidence would pretend a need. |
