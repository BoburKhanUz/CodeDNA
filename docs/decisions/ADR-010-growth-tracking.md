# ADR-010: Growth Tracking — An Observation Layer Over Deterministic Assessments

- **Status:** Accepted
- **Date:** 2026-10-15
- **Related:**
  - [ADR-004](ADR-004-dna-scoring.md)
  - [ADR-007](ADR-007-ai-interpretation.md)
  - [ADR-008](ADR-008-coding-challenges.md)
  - [ADR-009](ADR-009-learning-roadmap.md)
  - [Growth tracking v1](../architecture/growth-tracking-v1.md)

## Context

By Phase 17, CodeDNA measures code (Phases 11–14), offers practice
(Phase 16) and plans learning (Phase 17). Developers now want to see
whether their code has changed between analyses. That is easy to get
wrong in four ways:

- **Counting activity as growth.** If completed steps, passed challenges,
  AI text or self-report counted as growth, the feature would reward
  activity instead of measuring code, contradicting ADR-008 and ADR-009.
- **Inventing comparisons.** Comparing against an assumed zero, against
  assessments measured with other versions, or treating missing evidence
  as 0 would show changes that never happened.
- **A second scoring engine.** A growth score, or a recalculation of past
  results, would duplicate and drift from the deterministic engines
  (ADR-004).
- **Noise.** Reporting every 0.0001 movement as improvement or regression
  would be misleading.

## Decision

### 1. Observe, never score

Growth reads stored DNA, competency and skill gap snapshots, and nothing
else. It compares them with a versioned, fingerprinted rule set
(`GrowthRules` 1.0.0). It never:

- re-analyzes, re-scores or re-assesses;
- writes to an analysis table;
- produces an aggregate score.

Summaries are categorical counts. No AI is used.

### 2. Only code assessments are evidence

The unit of growth is one assessment: a skill gap snapshot of a successful
analysis run, with its lineage.

- Roadmap completions and passed challenges are shown next to a comparison
  as context, in a separate box, with non-causal wording.
- They are never read by the engine and never stored in growth.
- Only a new code analysis can show change.

### 3. The immediately preceding, compatible assessment

The baseline is the immediately preceding assessment of the same project,
by analysis run completion. The outcome depends on that baseline:

| Baseline | Outcome |
|---|---|
| None | `NOT_ESTABLISHED`, with no observations. |
| Measured with any differing DNA, metrics, competency, skill gap or target profile version or specification | `INCOMPARABLE`, with no observations. The baseline is recorded and not skipped. |

So no baseline ≠ zero, and incomparable ≠ regression.

### 4. Conservative, documented classification

- **Arithmetic.** Fixed point (4 decimals). A delta is `current − previous`,
  with the better direction per metric type.
- **Meaningful delta.** 0.0500 inclusive: Phase 14's material-gap
  resolution.
- **Evidence quality.** At least 0.6000 on both sides: Phase 14's HIGH
  bound.
- **Unmeasured evidence.** Missing, unsupported or unavailable values are
  `INSUFFICIENT_EVIDENCE`, never zero, improvement or regression.
- **Gap transitions.** `GAP → NO_GAP` is `IMPROVED`, and `NO_GAP → GAP` is
  `REGRESSED`.
- **Level transitions.** These are observations, not statuses.

### 5. Automatic, best-effort, idempotent, immutable

- **Automatic.** Growth is calculated as the last stage of the analysis
  job. A failure is logged and never invalidates the run or its snapshots.
  `growth:calculate` recovers.
- **Idempotent.** One snapshot per (assessment, rules version): a row lock
  plus a unique constraint.
- **Immutable.** Database triggers and constraints keep snapshots and
  observations immutable. A new rules version adds snapshots and never
  rewrites history.
- **Provenance.** Composite foreign keys tie both lineages to real
  assessments of the same project and owner.

### 6. Read-only, owner-only API

There are three GET endpoints, with no mutation and no client-chosen value,
snapshot, baseline or rules version. Archived projects stay readable.

## Consequences

### Positive

- Every change shown is traceable to two stored assessments, one rule set
  and one fingerprint, and reproducible.
- Activity can never inflate growth, and version upgrades never show fake
  changes.
- Earlier phases are untouched. Growth can be recalculated for any rules
  version without touching them.

### Negative

- A change of the DNA, competency, skill gap or target version breaks the
  chain: the next comparison is `INCOMPARABLE`, and the trend restarts.
  This is deliberate: honest gaps beat silent conversion.
- Small real improvements below 0.0500 show as "No meaningful change".
- Thin evidence (below 0.6000) claims no change even when the values move.
- Only the immediately preceding assessment is compared. Long-range
  history is the later historical DNA phase.

## Alternatives considered

| Alternative | Why it was rejected |
|---|---|
| A single growth score | It invents a new aggregate, hides direction per metric, and invites ranking. |
| Compare with the first or best assessment | It is not "growth since last time", and it is sensitive to outliers. |
| Skip incompatible baselines and compare with an older compatible one | It silently hides a change of measurement. |
| Convert old values to new versions | It is a second scoring engine and not reproducible. |
| Count learning activity or passed challenges | It contradicts ADR-008 and ADR-009: activity is not code. |
| Calculate growth on request | Growth would depend on when someone looked. Automatic plus idempotent is simpler and deterministic. |
| AI-written growth narratives | Non-deterministic, and ADR-007 limits AI to non-authoritative explanation. |
