# Skill Gap Analysis, Version 1 (skill gap version 1.0.0)

How CodeDNA compares a competency matrix with a versioned target (Phase 14).
Related:

- [competency-matrix-v1.md](competency-matrix-v1.md) (the current scores)
- [dna-scoring-v1.md](dna-scoring-v1.md)
- [API](../api/README.md#skill-gaps)
- [frontend](frontend.md#skill-gaps-appprojectsprojectskill-gaps)

> Skill Gap results describe measurable differences between observed source-code competency evidence and a versioned target definition. They do not establish developer seniority, intelligence, personality, professional worth, or future potential.

## Purpose

The analysis answers one question: **which measurable engineering
competencies have a meaningful gap between the current evidence and a
defined target?**

A gap means "the current measured competency score is below the configured
target". It never means the developer is weak, junior or lacking ability,
and it never suggests courses or learning content; that is for Phase 17. It
contains no AI interpretation (Phase 15) and no aggregate "developer gap".

```text
Verified AnalysisResult -> DNA snapshot -> competency snapshot -> SKILL GAP ENGINE -> skill gap snapshot + results
                                           (current scores)       (targets: spec)     (gap, materiality, priority)
```

## Target profile

Version 1.0.0 has one profile: **`ENGINEERING_STANDARD` 1.0.0**. It is a
defined, measurable engineering standard for the analyzed code, not a job
title or a seniority level. There are no Junior, Senior or role targets,
and no custom, team or user targets.

| Competency | Target | Rationale |
|---|---|---|
| `COMPLEXITY_MANAGEMENT` | **0.75** | Inside the ESTABLISHED band (0.65–0.8499): average and concentrated branching close to the best thresholds |
| `FUNCTION_DESIGN` | **0.75** | Inside the ESTABLISHED band: few long functions, wide parameter lists or deep nesting |
| `TYPE_STRUCTURE` | **0.75** | Inside the ESTABLISHED band: few types above the length threshold |
| `CODE_HYGIENE` | **0.90** | Syntax validity is expected of analyzable code: 0.90 still allows about 2.5% of files with syntax errors |

**The target values are product calibration choices and are not presented
as empirical industry standards.**

Targets live only in `App\Services\SkillGap\SkillGapSpecification`. The
client never sends a target, gap, priority or version: the API has no write
route and ignores query parameters.

## Formulas

```text
raw_gap      = max(target_score − current_score, 0)      (never negative)
material_gap = raw_gap ≥ 0.05
```

- **`current_score`** is the competency snapshot's stored score. Nothing is
  re-scored: no DNA, no competency, no analyzer metrics.
- **Arithmetic** is integer fixed-point with 4 decimal places
  (`App\Services\Dna\FixedPoint`), the same as for scores. A subtraction is
  exact, so no rounding is needed.
- **Stored values.** `current_score`, `target_score` and `raw_gap` are
  stored separately, so history stays interpretable. The raw gap is kept
  even when it is not material, for future recalibration.
- **Material-gap threshold 0.05.** It is a calibration choice. For a share
  component, 0.05 corresponds to about one function in a hundred; smaller
  differences are within the resolution of the measurements. A raw gap of
  exactly 0.0500 is material.
- **Levels are context only.** A competency's level is shown next to the
  gap, but the gap is always the numeric difference of scores, never
  "Developing → Strong".

## Priority

Only a material gap has a priority:

| Priority | Raw gap |
|---|---|
| `LOW` | 0.05 ≤ gap < 0.15 |
| `MEDIUM` | 0.15 ≤ gap < 0.30 (wider than one competency level band) |
| `HIGH` | gap ≥ 0.30, **and** evidence quality ≥ 0.60 |

- A gap of 0.30 or more with evidence quality below 0.60 is **capped at
  MEDIUM**, with `priority_capped: true`. A large difference measured on
  thin evidence is never promoted to HIGH.
- Priorities rank measurable differences; they carry no psychological
  urgency and say nothing about a person.
- The bounds are calibration choices.

## Statuses and missing data

| Status | When | `current_score` | `raw_gap` | `priority` |
|---|---|---|---|---|
| `GAP` | assessed, raw gap ≥ 0.05 | yes | yes | yes |
| `NO_GAP` | assessed, raw gap < 0.05 (including 0) | yes | yes (kept) | null |
| `INSUFFICIENT_EVIDENCE` | competency not assessed: too little code | null | null | null |
| `UNSUPPORTED` | competency not assessed: unsupported for the analyzed languages | null | null | null |
| `MISSING` | competency not assessed, or absent from the snapshot | null | null | null |
| `NOT_TARGETED` | the profile has no target for the competency | as stored | null | null |

- **No evidence means no gap**, never `target − 0`. The target is still
  shown, and the competency's evidence quality is preserved.
- **A measured 0.0000 is evidence:** against 0.90 it is a 0.90 gap.
- **Not targeted** does not mean a target of 0; no gap is computed.
- **Snapshot status:**
  - `GAPS_IDENTIFIED`: at least one GAP.
  - `NO_MATERIAL_GAPS`: at least one competency measured and none
    material.
  - `INSUFFICIENT_DATA`: no targeted competency measured.

  "No material gaps" and "no data" are always different states.

## Evidence quality, languages and project size

- **Evidence quality** is copied from the competency, which takes it from
  the DNA data quality. It is not inflated or recomputed, and it is never
  called confidence or probability. It only affects whether HIGH is
  allowed.
- **Language limitations** (C/C++ without a preprocessor) are copied from
  the competency to the result unchanged. Unsupported evidence makes a
  competency `UNSUPPORTED`, never a gap. There are no language-specific
  targets.
- **Project size** never changes a gap: the same score against the same
  target gives the same gap, as a test checks with ten times the code.
  Size reaches only evidence quality.

## Versioning, fingerprint and compatibility

- `CODEDNA_SKILL_GAP_VERSION` (only `1.0.0`) selects the specification and
  its target profile. The configuration validator rejects unknown values.
- The fingerprint is the SHA-256 of the canonical JSON of the definition:
  version, competency versions, profile key, version, description, targets
  and rationales, threshold, priority bounds and evidence-quality bound.
  - 1.0.0: `2dbc9aad5c196c26d02731c752afee32ba19efdc1ab7d54be69a190ec7331e13`
  - A test pins it, and another shows that changing any part changes it.
- Any change to a target, threshold or priority rule needs a new version
  (and, for targets, a new profile version). Existing snapshots are never
  reinterpreted.
- **Compatibility checks before analyzing:**
  1. The competency snapshot exists.
  2. Its competency version is supported (1.0.0 reads competency 1.0.0
     only).
  3. Its stored specification fingerprint equals the definition of that
     competency version. Otherwise the result is
     `COMPETENCY_SNAPSHOT_INVALID`, not a silent reinterpretation.

  The DNA scoring compatibility was already checked by the competency
  engine.

## Storage

- **`skill_gap_snapshots`**: one immutable row per (competency snapshot,
  skill gap version, target profile).
  - Columns: the versions, the target profile and its version, the
    fingerprint, the status, a summary (counts per status and priority,
    with **no aggregate**) and provenance.
  - Lineage (competency snapshot, DNA snapshot, run, source snapshot,
    project, owner) is one composite foreign key onto a new unique index on
    `competency_snapshots`.
- **`skill_gap_results`**: one immutable row per competency, with the
  numbers as columns. Later phases (17, 18) can query active gaps by
  project, competency, status and priority without unpacking JSON.
  - A composite foreign key ties every row to its snapshot's project and
    owner.
  - CHECK constraints enforce `raw_gap = GREATEST(target − current, 0)`,
    GAP ⇔ material, priority ⇔ GAP, and measured ⇔ scores present.
  - Language limitations and the competency's evidence (source, status,
    value, score) are kept as JSONB for reproducibility.

There are no updates, no deletes (the models refuse them) and no
cascades. See [data-model.md](data-model.md#skill_gap_snapshots).

## Calculation

`App\Actions\SkillGap\CalculateSkillGapSnapshot::handle($competencySnapshotId)`
runs in one transaction:

1. Locks the competency snapshot row.
2. Returns the existing snapshot for the key.
3. Runs the compatibility checks.
4. Analyzes and inserts the snapshot and its results.

It never calls the analyzer or re-scores. The row lock serializes callers,
and the unique constraint backs it up; 8 forked processes create one
snapshot with 4 results.

- **The analysis job** runs it right after the competency matrix. This is
  best effort: a failure is logged as `skill_gap.failed` and never fails
  the run, the DNA snapshot or the competency snapshot. Success logs
  `skill_gap.calculated`.
- **`php artisan skill-gap:calculate {competency_snapshot}` or
  `--missing`** retries or backfills. It is idempotent and never touches
  existing snapshots.

Failures, with fixed, safe messages:

- `COMPETENCY_SNAPSHOT_NOT_FOUND`
- `COMPETENCY_VERSION_UNSUPPORTED`
- `COMPETENCY_SNAPSHOT_INVALID`

## API and UI

- `GET /api/v1/projects/{project}/skill-gaps` (newest first, paginated,
  summaries) and `.../skill-gaps/{snapshot}`. Both are owner-only (404
  otherwise) and read-only (405 for every write).
- `/app/projects/[project]/skill-gaps`, linked from the Competency Matrix,
  shows:
  - the target profile and thresholds;
  - the material-gap and priority counts;
  - one card per competency: status, priority, current and target scores
    (out of 100), gap in points, evidence quality, level as context,
    language limitations, and evidence with the target rationale;
  - provenance.

  The page shows the no-data, insufficient-evidence and
  no-material-gaps states, using fixed product text only. The frontend
  computes nothing.

## Determinism

The same competency snapshot under the same specification and profile
always gives the same result:

- targeted competencies follow the profile's order, and untargeted ones
  follow sorted by key;
- input order does not matter;
- arithmetic is integer only, with no clock, randomness, network or AI.

Tests cover every boundary: just below, at and above the material
threshold and each priority bound, and the evidence-quality cap.

## Deferred

- Further target profiles: role, team or custom. They must stay neutral,
  never seniority labels.
- AI explanations are Phase 15; learning recommendations are the Phase 17
  [learning roadmap](learning-roadmap-v1.md), which reads these gaps and
  never changes them.
- Growth over time (Phase 18). The history list is available in the API.
