# ADR-004: DNA Scoring — Determinism, Versioning and Evidence

- **Status:** Accepted
- **Date:** 2026-10-05
- **Related:** [ADR-002](ADR-002-analysis-engine.md), [Analyzer architecture](../architecture/analyzer.md), [Internal analyzer contract](../api/internal-analyzer-contract.md)

## Context

The DNA score is the product's central output. Users will compare scores over
time, and later teams will too. If scores are irreproducible, invented or
silently redefined, the product loses its credibility. The scoring formulas
will also improve over time, and old results must stay interpretable.

## Decision

### 1. Deterministic pipeline

`same source snapshot + same versions ⇒ byte-identical deterministic result`.

The analyzer guarantees this as follows:

- Files are traversed in **sorted order** of their normalized relative POSIX
  path (byte-wise comparison of UTF-8). Parallel processing is allowed, but
  results are merged in that same sorted order.
- Analysis output never depends on wall-clock time, randomness, locale,
  environment variables, hostname or hash seeds.
- **Decimal arithmetic for scores.** Normalization and scoring use Python
  `decimal.Decimal` with `ROUND_HALF_UP`. Scores are stored and transmitted
  with **4 decimal places** on a **0–1 scale**. User interfaces display
  `round(score × 100)` (0–100, half-up).
- Raw metric aggregates (means, medians, percentiles) use documented
  definitions: the percentile method is fixed (nearest-rank), the median of
  an even-sized set is the mean of the two middle values, and so on. They are
  rounded to 4 decimal places before entering scoring.
- Deterministic sections of the result are serialized as **canonical JSON**
  (sorted keys, no insignificant whitespace, UTF-8, no NaN/Infinity). Their
  SHA-256 is returned as `result_hash`. Timing and other diagnostic fields
  are excluded from the hash.
- All dependencies, including Tree-sitter grammars, are pinned exactly.

### 2. Versioning

Every analysis result records these versions:

| Version | Bumped when | Format |
|---|---|---|
| `analyzer_version` | Any analyzer release | SemVer, e.g. `0.1.0` |
| `ir_version` | The IR schema changes | `MAJOR.MINOR` |
| `metrics_version` | Any metric definition or extraction change that can alter a value (including grammar upgrades that change outputs) | `MAJOR.MINOR` |
| `scoring_version` | Any change to normalization thresholds, weights, dimension definitions or aggregation | `MAJOR.MINOR` |
| `contract_version` | The internal HTTP contract changes | `MAJOR.MINOR`; the major is in the URL (`/internal/v1`) |

The marketing name "DNA Engine vX.Y" corresponds to `scoring_version`.

Within a `MAJOR`, a `MINOR` bump may only add fields or dimensions. Changing
the meaning of an existing value requires a `MAJOR` bump.

### 3. Immutable snapshots

- A DNA snapshot is written once per completed analysis run and **never
  updated**. Re-scoring creates a new snapshot.
- Snapshots are directly comparable only when `metrics_version` and
  `scoring_version` are identical. Comparisons across versions must be
  labelled as such in the UI. Re-analysis from a retained source snapshot
  (ADR-003) is the way to bring old data onto a new version.

### 4. Evidence-based scores, no invented numbers

- Every dimension score has a **status**: `scored`, `insufficient_data` or
  `not_assessed`. A score is present only when the status is `scored`.
- Each dimension declares **minimum evidence** in its scoring definition (for
  example, at least N functions or N lines of code in supported languages).
  Below that threshold the status is `insufficient_data` and the score is
  `null`. A default value is never substituted.
- The **overall score** is the weighted mean of the `scored` dimensions only,
  with weights renormalized. If fewer than the defined minimum number of
  dimensions are scored, the overall score is `null`.
- An LLM never produces, adjusts or overrides a score (master instruction
  §15).

### 5. Documented formulas, no magic numbers

- All thresholds, weights and evidence minimums live in a **versioned scoring
  definition file** inside the analyzer (for example
  `analyzer/app/scoring/definitions/v1_0.*`), not scattered through code.
- The formula for each scoring version is documented in
  `docs/architecture/dna-scoring-v<major>.md`. This file is created in
  Phase 11, before that scoring version is released.
- Golden regression fixtures (input snapshot → expected features and scores)
  are mandatory. A change that alters any golden output must come with the
  appropriate version bump.

### 6. Initial dimensions (scoring 1.0 — proposal, finalized in Phase 11)

| Dimension | Status in 1.0 | Main evidence (metrics) |
|---|---|---|
| `readability` | scored | function length distribution, nesting depth, line length |
| `naming` | scored | convention conformance per language/role, identifier length, abbreviation ratio |
| `consistency` | scored | naming-style consistency, indentation/quote/formatting consistency |
| `modularity` | scored | file/type/function size distributions, parameter counts, functions per file |
| `complexity_management` | scored | cyclomatic and cognitive complexity percentiles, maximum nesting |
| `documentation` | scored | doc comment coverage of public types/functions, comment density |
| `architecture` | `not_assessed` | Static evidence from a single snapshot is too weak; revisit once dependency and layer analysis exists |
| `maintainability` | `not_assessed` | Would mostly double-count other dimensions; revisit as an explicitly defined composite |

### 7. Developer-level DNA (proposal)

For the MVP, a developer's DNA is the LOC-weighted mean, per dimension, of
the **latest completed snapshot of each of their projects**. Only snapshots
with the **same `scoring_version`** are combined. This is computed by Laravel
from stored snapshots and is itself versioned under that `scoring_version`.
It is confirmed in Phase 11.

## Consequences

- Scores are reproducible and auditable, and algorithm improvements don't
  corrupt history.
- Some dimensions will show `insufficient_data` or `not_assessed`, which is
  better than invented numbers.
- Golden fixtures add maintenance cost whenever the analyzer changes. That
  cost is deliberate.

## Alternatives considered

- **Floating-point scoring:** rejected. Results can vary across platforms and
  library versions at the last digit, which breaks byte-identical
  reproducibility.
- **Percentile-ranking developers against a population:** deferred. It needs
  a reference population and makes scores change when other users join.
  Absolute, threshold-based normalization comes first.

## Open questions

- **Authorship attribution:** in the MVP, uploaded code is attributed to the
  uploading user by their own declaration. Commit-level attribution needs
  repository history (Phases 19/20). The UI must state this limitation.
