# Competency Matrix, Version 1 (competency version 1.0.0)

How CodeDNA derives a versioned, deterministic competency matrix from a DNA
snapshot (Phase 13). Related:

- [dna-scoring-v1.md](dna-scoring-v1.md): the DNA evidence the matrix is built from
- [metrics-v1.md](metrics-v1.md): the underlying analyzer metrics
- [API](../api/README.md#competencies)
- [frontend](frontend.md#competency-matrix-appprojectsprojectcompetencies)

> CodeDNA competency results describe deterministic evidence observed in analyzed source code. They do not establish developer seniority, intelligence, personality, professional level, or future potential.

## Purpose

The matrix answers one question: **which engineering competencies are
supported by the analyzed code evidence, and how strongly?**

It does not answer "how senior is this developer". There are no Junior,
Middle, Senior or Expert labels, no statements about a person, and no
aggregate competency score that could be read as one.

```text
Verified AnalysisResult -> DNA scoring engine -> DNA snapshot -> COMPETENCY ENGINE -> competency snapshot
                                                     (evidence per component)          (score, level, evidence per competency)
```

The calculation is a pure function of (DNA snapshot, competency
specification). There is no AI, LLM, network call, randomness, clock or
floating point. AI interpretation is Phase 15 and never produces these
values.

## What a competency is and is not

A competency here is a **named, documented grouping of measured DNA
evidence**: an interpretation of the code characteristics, not of the
author.

| A competency level is | A competency level is not |
|---|---|
| How strongly the analyzed code meets defined, measurable criteria | Developer seniority, career or employment level |
| Valid for this source snapshot and these versions | A statement about intelligence, potential, personality or professionalism |
| Reproducible from stored evidence | An AI judgment or a probability |

## Competencies (1.0.0)

Each competency maps onto **raw DNA components**: the normalized
per-measurement scores the scoring engine stored with their counts, not
whole dimension scores.

Each of the seven DNA 1.0.0 components is evidence of **exactly one**
competency. The specification rejects double counting, and a test checks
that the mapping covers every component once. The competencies regroup the
evidence by meaning instead of repeating the three DNA dimensions.

| Key | Name | Evidence (DNA component, weight) | Why this evidence |
|---|---|---|---|
| `COMPLEXITY_MANAGEMENT` | Complexity management | `COMPLEXITY.mean_cyclomatic_complexity` 0.60 · `COMPLEXITY.complex_function_share` 0.40 | Decision logic per function: average branching, and how much of it is concentrated in functions above the complexity threshold |
| `FUNCTION_DESIGN` | Function design | `STRUCTURE.long_function_share` 0.40 · `STRUCTURE.long_parameter_list_share` 0.30 · `COMPLEXITY.deep_nesting_share` 0.30 | Shape of a function: length, interface width and body depth. Nesting moves here from the COMPLEXITY dimension because it describes how a function is laid out, not how many decisions it makes |
| `TYPE_STRUCTURE` | Type structure | `STRUCTURE.large_type_share` 1.00 | Size of classes, interfaces, structs, traits and enums. Needs at least 3 types; code without types is not assessed rather than penalized |
| `CODE_HYGIENE` | Code hygiene | `CODE_HYGIENE.syntax_error_share` 1.00 | Syntax validity of analyzable files. A **direct mapping** of the CODE_HYGIENE dimension, kept as a competency because validity is distinct from design |

All evidence of 1.0.0 is **required**. The engine supports optional
evidence (skipped when unavailable, with the remaining weights
renormalized) for later versions.

**Deferred (not defined, because there is no supporting evidence):**

- **CODE_STRUCTURE** (module or package organization) needs dependency or
  module-graph evidence; metrics 1.0 has only import counts.
- **Database, testing, security, documentation and naming** competencies:
  the analyzer measures none of them.

## Formulas

```text
competency_score = Σ(component_score × weight) / Σ(weight)        over AVAILABLE evidence
evidence_quality = 0.50 × parse_coverage + 0.25 × evidence_volume + 0.25 × evidence_availability
```

- **`component_score`** is the DNA snapshot's stored 4-place score for
  that component, normalized by the scoring engine from raw counts
  (thresholds in [dna-scoring-v1.md](dna-scoring-v1.md#specification-100)).
  It is not re-normalized.
- **`parse_coverage` and `evidence_volume`** are the DNA snapshot's stored
  data-quality terms: files parsed out of files analyzable, and functions
  out of 50 (capped at 1).
- **`evidence_availability`** is the AVAILABLE evidence of this competency
  divided by all of its evidence.
- **Arithmetic** is integer fixed-point with 4 decimal places, rounded half
  up at every division (`App\Services\Dna\FixedPoint`). Results are decimal
  strings on a 0–1 scale.

`evidence_quality` describes how much measurable input the competency had.
It is **not** a confidence, a probability or an error bar, and it never
changes the score or the level.

## Levels

| Level | Value | Score range | Meaning (about the code only) |
|---|---|---|---|
| 0 | `NOT_ESTABLISHED` | 0.0000 – 0.3999 | The analyzed code does not meet the defined criteria for this competency |
| 1 | `DEVELOPING` | 0.4000 – 0.6499 | The analyzed code meets the defined criteria in part |
| 2 | `ESTABLISHED` | 0.6500 – 0.8499 | Available source-code evidence meets the defined threshold for this competency |
| 3 | `STRONG` | 0.8500 – 1.0000 | The analyzed code demonstrates strong evidence for this competency |

- A score gets the highest level whose lower bound it reaches, so exactly
  0.6500 is ESTABLISHED.
- The bounds are version 1 calibration choices on the component-score
  scale, not empirical claims. A component score of 1 means the evidence
  is at or better than the "best" threshold of its DNA measurement.
- **A level exists only for an assessed competency.** A low score with
  evidence is NOT_ESTABLISHED; no evidence means no level at all (see
  below).

## Missing, insufficient and unsupported evidence

Each piece of evidence carries the DNA component's status unchanged:
`AVAILABLE`, `INSUFFICIENT_EVIDENCE`, `UNSUPPORTED` or `MISSING`.

If all required evidence is AVAILABLE, the competency is **`ASSESSED`**. If
not, the competency status is the reason, chosen in this order:

1. `UNSUPPORTED`: the analyzer cannot measure the evidence for these
   languages;
2. `MISSING`: absent from the DNA snapshot;
3. `INSUFFICIENT_EVIDENCE`: below a minimum count, e.g. fewer than 5
   functions or 3 types.

An unassessed competency has **no score and no level**. It is never a score
of 0 and never level 0. A **measured** 0, such as many files with syntax
errors, is evidence: it scores 0.0000 and is NOT_ESTABLISHED.

One unassessed competency never lowers another. The snapshot status is
`ASSESSED` when at least one competency is assessed, and
`INSUFFICIENT_DATA` otherwise. Every competency is stored either way.

## Language limitations

Competencies use the project-level (`metrics.overall`) evidence that DNA
scoring already combined. Per-language scores are not averaged and no
language weighting is invented. The languages the analysis measured (the
keys of the stored result's `metrics.by_language`, read in SQL without
loading the result) are recorded in the snapshot's provenance.

For each measured language with a documented limitation, the affected
competencies carry a `limitations` entry. The score is unchanged.

- **C and C++** are parsed without a preprocessor: decisions hidden in
  macros are not counted, and `#if` branches can cause syntax errors.
  This is noted on COMPLEXITY_MANAGEMENT, FUNCTION_DESIGN and
  CODE_HYGIENE.
- Metrics that a language cannot provide arrive as `UNSUPPORTED` and make
  the competency unassessed instead of low. No 1.0.0 evidence is currently
  unsupported for any language: `types_with_bases`, the one unsupported
  metric, is not used.

## Project size

All evidence is ratios (shares of functions, types or files, and average
complexity per function), so a larger project neither scores better nor
worse. A test checks that ten times the code with the same proportions
gives identical scores and levels. Size affects only:

- **sufficiency**: the minimum counts;
- **evidence quality**: evidence volume reaches its maximum at 50
  functions.

No "more lines" or "more functions" metric exists.

## Versioning and fingerprint

- `CODEDNA_COMPETENCY_VERSION` (default and only supported value: `1.0.0`)
  selects the specification in
  `App\Services\Competency\CompetencySpecification`. The configuration
  validator rejects unknown versions at boot.
- Version 1.0.0 reads DNA scoring version 1.0.0 only. A DNA snapshot of
  another scoring version is rejected (`DNA_SCORING_VERSION_UNSUPPORTED`)
  rather than misread.
- The **specification fingerprint** is the SHA-256 of the canonical JSON of
  the full definition: competencies, descriptions, evidence mapping,
  weights, rationales, partial languages, level bounds and evidence-quality
  weights.
  - 1.0.0: `f9019e29ed358920eb9e12856aecc9f07450c6466ce174b2dc191c7a64f29eac`
  - A test pins this value, and another shows that changing any part of
    the definition changes it.
- Any change to a competency, mapping, weight, bound or formula requires a
  new competency version. A new version creates new snapshots, and old
  ones stay as they are.

## Storage

`competency_snapshots` holds one immutable row per (DNA snapshot,
competency version), and all competencies of that version live in one
JSONB array:

- **Why JSONB.** The set is small and fixed per version, always read
  together, and not yet filtered individually. A child table can come with
  a later version if querying across competencies is needed.
- **Lineage.** `dna_snapshot_id`, `project_id`, `analysis_run_id`,
  `source_snapshot_id` and `user_id` form **one composite foreign key**
  onto `dna_snapshots`, backed by a new unique index on those columns. The
  copies can therefore never disagree with the DNA snapshot.
- **Constraints.**
  - `UNIQUE (dna_snapshot_id, competency_version)`
  - `RESTRICT` deletes (no cascades)
  - CHECKs on status, fingerprint format and JSON shapes and sizes
  - no `updated_at`; the model refuses updates and deletes
- **Provenance** stores:
  - the competency version and fingerprint;
  - the DNA snapshot ID, scoring version, scoring fingerprint, status,
    overall score and data quality;
  - the analysis run, source snapshot, result hash, metrics version and
    measured languages.

The Phase 05 `dna_snapshots.competencies` column stays `NULL`: writing it
would mean updating an immutable DNA snapshot, and a competency version
must be able to change without touching DNA history.

See [data-model.md](data-model.md#competency_snapshots).

## Calculation

`App\Actions\Competency\CalculateCompetencyMatrix::handle($dnaSnapshotId)`
runs in one transaction:

1. Locks the DNA snapshot row.
2. Returns the existing snapshot for (DNA snapshot, version) without
   reading anything else.
3. Checks the DNA scoring version.
4. Reads the measured languages.
5. Assesses and inserts.

The row lock serializes concurrent calls, and the unique constraint backs it
up; a forked-process test shows that 8 concurrent calls create one
snapshot. The action never calls the analyzer, downloads or parses source,
or loads the analysis result.

Triggers:

- **The analysis job.** Right after a static_analysis run is scored, the
  job assesses competencies. This is best effort:
  - a failure is logged (`competency.failed` with a failure code) and
    never changes the run or its DNA snapshot;
  - success logs `competency.calculated`.
- **`php artisan competency:calculate {dna_snapshot}` or `--missing`.**
  Retries, backfills DNA snapshots created before Phase 13, and assesses
  under a new version. It is idempotent.

Failures (`CompetencyFailure`), with fixed, safe messages:

- `DNA_SNAPSHOT_NOT_FOUND`
- `DNA_SCORING_VERSION_UNSUPPORTED`
- `DNA_SNAPSHOT_INVALID`: stored evidence that does not have its scoring
  version's shape.

## API and UI

- `GET /api/v1/projects/{project}/competencies` (newest first, paginated)
  and `.../competencies/{snapshot}`. Both are owner-only (404 otherwise)
  and read-only (405 for writes), and return the stored values unchanged.
  See the [API docs](../api/README.md#competencies).
- `/app/projects/[project]/competencies` shows one card per competency:
  - score shown on 0–100, level badge, evidence quality and a neutral
    meaning;
  - an expandable "Why this result" with every piece of evidence and its
    CodeDNA source, counts, measured value, thresholds and weight, plus the
    evidence-quality terms and the level boundaries;
  - provenance, linked to the CodeDNA assessment.

  The CodeDNA dashboard links to it, and it links on to the
  [skill gaps](skill-gap-v1.md). The frontend computes nothing.

## Determinism

The same DNA snapshot under the same competency version always gives the
same matrix:

- competencies and evidence follow the specification's order;
- languages and metric paths are sorted;
- the order of keys in the input does not matter;
- arithmetic is integer only.

The tests cover repeated runs, reordered input, every level boundary
(just below, exactly at, just above), rounding, provenance and linkage.
