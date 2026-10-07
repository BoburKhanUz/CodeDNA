# ADR-012: Historical DNA — A Read Model Over Immutable Snapshots

- **Status:** Accepted
- **Date:** 2026-10-17
- **Related:**
  - [ADR-004](ADR-004-dna-scoring.md)
  - [ADR-010](ADR-010-growth-tracking.md)
  - [ADR-011](ADR-011-github-integration.md)
  - [Historical DNA v1](../architecture/historical-dna-v1.md)

## Context

Growth tracking (Phase 18) answers "what changed between an assessment and
the one before it?". Developers also need the whole sequence: what the
project's DNA, competencies and skill gaps looked like at every
assessment, how a gap opened and closed, where the scoring version
changed, and where each analyzed source came from.

That view is easy to get wrong in four ways:

- **A second copy of the scores.** A history table would duplicate every
  value and could drift from the snapshots it copies.
- **A second scoring or growth engine.** Recalculating past scores, or
  computing deltas outside Phase 18's rules, would contradict ADR-004 and
  ADR-010.
- **Lines across versions.** Connecting points scored with different
  versions would draw a trend that never happened.
- **Rewriting history.** Showing a resolved gap's current state for every
  past assessment would erase the gap.

## Decision

### 1. A read model, no persistence

Historical DNA reads the existing immutable rows:

- `dna_snapshots`, `competency_snapshots`, `skill_gap_snapshots` and
  `skill_gap_results`;
- `growth_snapshots`;
- `source_snapshots` and `analysis_runs`.

It adds no table, column, index or migration. Every value is passed through
as stored. Lineage, time, versions and provenance are already stored with
foreign keys and indexes that serve the queries.

### 2. A point is an eligible DNA snapshot, with its layers

A point is a DNA snapshot of the project and its owner, from a `SUCCEEDED`,
completed run. Points are ordered by run completion, in Phase 18's order.

Its competency and skill gap layers are the newest snapshots derived from
it. A missing layer is reported `UNAVAILABLE`. It is never fabricated, and
the point is never dropped.

### 3. Server-defined segments; lines never cross them

Each point carries one segment key per layer, derived from that layer's
compatibility fields. The interface draws a line only:

- inside one segment;
- between consecutive measured values.

A single point is a baseline.

### 4. Comparison reuses Phase 18

A comparison of two points uses one of two bases:

- the stored growth snapshot, when the pair is exactly the later point and
  its stored baseline;
- otherwise, Phase 18's `GrowthEngine` and current rules, run in memory
  over the stored values.

Only the layers both points have are compared. Any compatibility
difference makes the comparison `INCOMPARABLE`, with no deltas. Nothing is
stored.

### 5. Clients send IDs only

The API accepts pagination and two snapshot IDs, and nothing else (`422`).
Ownership and compatibility are decided by the server. Other users'
projects and other projects' snapshots answer `404`.

### 6. Activity is context

Learning activity between two points is shown as counts, through the helper
growth uses. It is never evidence and never presented as a cause.

## Consequences

- No migration or backfill is needed. History covers every assessment ever
  made, including those before Phase 20.
- A new scoring, competency or skill gap version automatically starts a new
  segment. Old points keep their values.
- Immutability rests on the model guards (and growth's trigger). Database
  triggers on the DNA, competency and skill gap tables remain a future
  hardening item, because earlier phases' tests tamper with those rows
  directly.
- Each page costs a fixed number of queries, joining runs on the existing
  `(project_id, created_at)` DNA index. Very long histories would benefit
  from keyset pagination, a later concern.
- Developer-wide DNA across projects is deliberately out of scope.
