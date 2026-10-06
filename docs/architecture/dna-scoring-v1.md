# CodeDNA Scoring, Version 1 (scoring version 1.0.0)

How CodeDNA turns a verified static-analysis result into an immutable DNA
snapshot (Phase 11). Related:

- [ADR-004](../decisions/ADR-004-dna-scoring.md): determinism, versioning, evidence
- [metrics-v1.md](metrics-v1.md): the input metrics
- [data-model.md](data-model.md#dna_snapshots): storage
- [data-flow.md](data-flow.md#dna-scoring-phase-11): when scoring runs

> CodeDNA v1 measures deterministic characteristics of analyzed source code. It does not infer developer seniority, intelligence, ability, personality, or professional level.

## What CodeDNA v1 does not claim

- **No people judgments.** It assigns no seniority, level or label (junior,
  middle, senior, expert or similar) and no ranking of people. A score
  describes the analyzed code only, and only the characteristics listed
  below.
- **Not a quality verdict.** The thresholds are version 1 calibration
  choices, documented below. They are not empirical industry norms, and a
  low score is not proof of a defect.
- **No AI.** No LLM, ML model, external API or network call takes part in
  any calculation. AI interpretation (Phase 15) will read stored scores and
  will never produce them.
- **Partial coverage.** It measures what the analyzer measures for the
  analyzed files (complexity, nesting, sizes, syntax validity). It says
  nothing about naming, tests, documentation, architecture, security or
  correctness.
- **`data_quality` is not confidence.** It is not a probability that the
  score is right; it describes how much measurable input there was (see
  [Data quality](#data-quality)).

## Layers

```text
analyzer (Python)                      backend (Laravel)
FOUNDATION ──► STATIC ANALYSIS ───────► verified, persisted ──► SCORING ──────────► DNA snapshot
(Phase 08)     raw metrics (Phase 09)   analysis_results         dimensions,          (immutable)
                                        (Phase 10)               overall score,
                                                                 data_quality (Phase 11)
                                                                          ┊
                                                                 AI interpretation (Phase 15, not built)
```

There are four separate kinds of data, and they are never mixed:

| Kind | Produced by | Stored in |
|---|---|---|
| Raw metrics and findings | analyzer | `analysis_results.result` |
| Dimension scores | scoring engine | `dna_snapshots.dimensions` |
| Overall score and data quality | scoring engine | `dna_snapshots.overall_score`, `data_quality` |
| Interpretation (competencies, strengths, weaknesses) | not in Phase 11 | `competencies`, `strengths`, `weaknesses` stay `NULL` |

### Where the code lives

| Class | Role |
|---|---|
| `App\Services\Dna\ScoringSpecification` | **The authoritative definition** of every scoring version: inputs, dimensions, weights, thresholds, minimum evidence, aggregation, data-quality formula. Validated on construction (weights sum to exactly 1, `best < worst`, ...) |
| `App\Services\Dna\Specification\*` | Typed parts of a specification (`DimensionSpec`, `ComponentSpec`, `DataQualitySpec`) |
| `App\Services\Dna\CodeDnaScoringEngine` | Pure function: (verified result, specification) → `DnaScore`. No database, clock or network |
| `App\Services\Dna\Normalizer`, `FixedPoint` | Normalization and integer fixed-point arithmetic |
| `App\Actions\Dna\CalculateDnaSnapshot` | Loads and re-verifies the stored result, scores it, and inserts the snapshot (idempotent) |
| `App\Console\Commands\ScoreAnalysisRuns` | `php artisan dna:score {run}` / `--missing` (backfill, retry) |
| `App\Enums\Dna\*` | `DnaDimension`, `DimensionStatus`, `EvidenceStatus`, `DnaScoringFailure` |

**Deviation from ADR-004 (for review).** ADR-004 places scoring in the
analyzer, with a definitions file. Phase 11 scores in Laravel, from the
result persisted and verified in Phase 10:

- the analyzer contract and the Phase 08/09 behavior stay unchanged;
- a run can be re-scored under a new scoring version without calling the
  analyzer or reading the source again;
- the result that is scored is exactly the one whose `result_hash` was
  verified and stored.

ADR-004's rules (determinism, decimal arithmetic, half-up rounding to 4
places, versioning, minimum evidence, renormalized weighted mean, golden
values) are kept. The version uses `MAJOR.MINOR.PATCH` (`1.0.0`, as set for
Phase 11), not ADR-004's `MAJOR.MINOR`.

## Input

Only a `SUCCEEDED` analysis run is scored, and only one whose stored
`analysis_results` row:

- has `result_type = static_analysis` (a `foundation` result has no
  metrics);
- has `metrics.version = "1.0"`;
- still hashes to the run's `result_hash` (recomputed with the same
  canonical JSON as the Phase 10 client);
- names the run's ID.

Scoring reads nothing else. It does not call the analyzer, re-parse, read
the archive, or accept any value from a client.

### Metrics available in a static_analysis result (audit)

All are produced deterministically by the analyzer (metrics 1.0, rule set
1.0, see [metrics-v1.md](metrics-v1.md)). `count` means a JSON integer ≥ 0
that is never null.

| Path | Type | Used by 1.0.0 |
|---|---|---|
| `metrics.overall.files_analyzable`, `files_parsed`, `files_parse_error` | count | yes |
| `metrics.overall.files_parse_timeout`, `files_limit_exceeded`, `files_unsupported_parser` | count | through `files_analyzable` only |
| `metrics.overall.functions_total`, `complexity_total`, `complexity_over_threshold`, `types` | count | yes |
| `findings.by_rule.structure/nesting-depth`, `structure/function-length`, `structure/parameter-count`, `structure/class-length` | count (all findings, also when `findings.items` is truncated) | yes |
| `findings.by_rule.parse/syntax-error` | count (= `files_parse_error`) | no (`files_parse_error` is used) |
| `findings.by_rule.complexity/cyclomatic` | count (= `complexity_over_threshold`) | no (`complexity_over_threshold` is used) |
| `metrics.overall.lines_*`, `ast_nodes`, `imports`, `functions`, `methods`, `anonymous_functions`, `types_by_kind` | count / object of counts | no |
| `metrics.overall.ast_max_depth`, `function_lines_max`, `parameters_max`, `nesting_max`, `complexity_max` | count **or null** (null when nothing was measured) | no |
| `metrics.overall.function_lines_avg`, `parameters_avg`, `nesting_avg`, `complexity_avg` | number (4 decimals) **or null** | no: ratios are recomputed exactly from counts |
| `metrics.overall.types_with_bases` | count **or null**; unsupported for Go, Rust, C (listed in `unsupported`) | no |
| `metrics.by_language.<language>.*` | same shape per language | no (v1 scores the whole run) |

Every input of 1.0.0 is a required, non-nullable integer in the published
schema. The engine still handles absent and null inputs explicitly (see
[Missing data](#missing-data)), so a future metrics version cannot turn a
gap into a score by accident. A present input that is not a non-negative
integer (for example `3.5`, `-1` or `"10"`) makes the result invalid
(`RESULT_INVALID`). So does an inconsistent share, such as more long
functions than functions.

C and C++ are parsed without a preprocessor, so their complexity and
nesting are partial ([metrics-v1.md](metrics-v1.md#support-by-language)).
Version 1.0.0 does not adjust for this.

## Dimensions

| Identifier | Name | Weight | What it measures |
|---|---|---|---|
| `COMPLEXITY` | Complexity | **0.40** | How much branching functions contain (cyclomatic complexity and nesting) |
| `STRUCTURE` | Structure | **0.40** | How large functions, parameter lists and types are |
| `CODE_HYGIENE` | Code hygiene | **0.20** | Whether analyzable files parse without syntax errors |

The weights sum to exactly 1.0000. Identifiers are stable: a dimension that
changes meaning gets a new scoring version, and a removed identifier is
never reused.

Two dimensions were considered and left out:

- **MAINTAINABILITY.** Every input it could use is already in
  `COMPLEXITY` or `STRUCTURE`. A separate dimension would count the same
  evidence twice (ADR-004 warns about this).
- **ANALYSIS_QUALITY.** How much could be analyzed is a property of the
  input, not of the code. It is reported as `data_quality` next to the
  score and is never weighted into it.

## Specification 1.0.0

Every component is a ratio of verified counts, normalized "lower is
better" between a `best` and a `worst` threshold.

| Dimension | Component | Weight in dimension | Value | Best (score 1) | Worst (score 0) | Minimum denominator | Required |
|---|---|---|---|---|---|---|---|
| COMPLEXITY | `mean_cyclomatic_complexity` | 0.50 | `complexity_total / functions_total` | ≤ 2.0 | ≥ 10.0 | 5 functions | yes |
| COMPLEXITY | `complex_function_share` | 0.25 | `complexity_over_threshold / functions_total` (complexity > 10) | 0 | ≥ 0.20 | 5 functions | yes |
| COMPLEXITY | `deep_nesting_share` | 0.25 | `by_rule[structure/nesting-depth] / functions_total` (nesting > 4) | 0 | ≥ 0.20 | 5 functions | yes |
| STRUCTURE | `long_function_share` | 0.40 | `by_rule[structure/function-length] / functions_total` (> 100 lines) | 0 | ≥ 0.10 | 5 functions | yes |
| STRUCTURE | `long_parameter_list_share` | 0.30 | `by_rule[structure/parameter-count] / functions_total` (> 5 parameters) | 0 | ≥ 0.20 | 5 functions | yes |
| STRUCTURE | `large_type_share` | 0.30 | `by_rule[structure/class-length] / types` (> 500 lines) | 0 | ≥ 0.20 | 3 types | no |
| CODE_HYGIENE | `syntax_error_share` | 1.00 | `files_parse_error / (files_parsed + files_parse_error)` | 0 | ≥ 0.25 | 3 files | yes |

Rationale for the thresholds (calibration choices, not norms):

- A function's cyclomatic complexity is at least 1. An average of 2 or less
  means mostly straight-line code. An average of 10 equals the analyzer's
  per-function warning threshold.
- The share thresholds say how common a finding must be to cost the whole
  component. Long functions (`> 100 lines`) cost it at 10% of functions;
  the other function findings at 20%.
- One file in four with syntax errors (25%) means the code that could be
  measured is not representative.
- With fewer than 5 functions (or 3 types or files), a ratio swings between
  extremes with each single function. The component is then reported as
  `INSUFFICIENT_EVIDENCE` instead of being scored.

The table is generated from `ScoringSpecification::v1_0_0()`. The
specification's fingerprint (SHA-256 of its canonical JSON,
`c07bd65575b0423973e072eaa55b6d28bb6a46e52290945646810983ba02763b`) is
pinned by `ScoringSpecificationTest` and stored in each snapshot's
`evidence.specification_fingerprint`.

## Normalization

```text
value = Σ numerator / Σ denominator        (exact rational, never rounded first)
score = 1                                  if value ≤ best
      = 0                                  if value ≥ worst
      = (worst − value) / (worst − best)   otherwise
```

The score is bounded to [0, 1] whatever the input. The normalization is
linear, and it is computed exactly on the rational value. The only rounding
is the final half-up rounding to 4 places. Raw metric values never become
scores directly.

Example (mean complexity, best 2, worst 10):

| Value | Score |
|---|---|
| 1.5 | 1.0000 |
| 2.0 | 1.0000 |
| 2.01 | 0.9988 |
| 6.0 | 0.5000 |
| 9.99 | 0.0013 |
| 10.0 | 0.0000 |
| 10.01 | 0.0000 |

## Missing data

Each component's evidence has exactly one status. The four statuses are
never conflated:

| Status | Meaning | Effect |
|---|---|---|
| `AVAILABLE` | Every input is an integer, and the denominator reaches the minimum | Scored. A measured **0** is evidence: zero findings scores 1 |
| `INSUFFICIENT_EVIDENCE` | Inputs exist, but the denominator is below the minimum | Not scored |
| `UNSUPPORTED` | An input is `null` and listed in the metric group's `unsupported` array | Not scored |
| `MISSING` | An input key is absent, or is `null` without being declared unsupported | Not scored |

When several inputs disagree, `UNSUPPORTED` takes precedence over
`MISSING`, which takes precedence over `AVAILABLE`.

- **Dimension.** A dimension is `SCORED` when all of its required
  components are `AVAILABLE`. Its score is the weighted mean of its
  `AVAILABLE` components, with their weights renormalized. An optional
  component that is not available (`large_type_share` with fewer than 3
  types, for example) is skipped, and the remaining weights are rescaled
  (0.40/0.70 and 0.30/0.70). Otherwise the dimension is `UNAVAILABLE`:
  - its `score`, `effective_weight` and `contribution` are `null`;
  - `unavailable_reason` holds the status of its first failing required
    component.
- **Overall.** An unavailable dimension is excluded, never counted as 0, and
  the weights of the scored dimensions are renormalized. The snapshot is
  `READY` only when at least **2** dimensions are scored. Otherwise it is
  `INSUFFICIENT_DATA`:
  - `overall_score` is `NULL` (enforced by a database CHECK);
  - the dimension details, the availability summary and `data_quality` are
    still stored.

Every snapshot stores in its `evidence` how many components fell under each
status (`availability`), which dimensions were scored or unavailable, the
scored weight, and whether the weights were renormalized.

## Aggregation

```text
dimension_score = Σ(component_score × component_weight) / Σ(component_weight)    over AVAILABLE components
overall         = Σ(dimension_score × weight) / Σ(weight)                        over SCORED dimensions
effective_weight(d) = weight(d) / Σ(weight of SCORED dimensions)
contribution(d)     = dimension_score(d) × effective_weight(d)
```

Equivalently, `overall = Σ contribution`. The overall score is rounded once
from the exact weighted sum. Each rounded `contribution` is reported for
explanation only, so their sum can differ from `overall_score` in the last
decimal place.

## Data quality

`data_quality` ∈ [0, 1] describes how much measurable input the score had.
It is built only from objective counts in the result:

```text
data_quality = 0.50 × parse_coverage       parse_coverage      = files_parsed / files_analyzable   (0 when there is no analyzable file)
             + 0.25 × evidence_volume      evidence_volume     = min(functions_total / 50, 1)
             + 0.25 × metric_availability  metric_availability = AVAILABLE components / all 7 components
```

- **Parse coverage.** Files that failed to parse, timed out, hit a limit or
  had no parser lower it.
- **Evidence volume.** Reaches 1 at 50 functions.
- **Metric availability.** Falls when components are missing, unsupported
  or too small to rate.

Each dimension also gets a `data_quality`, with the same formula and
`metric_availability` restricted to that dimension's components. It is
**not** a confidence level, a probability or an error bar, and it never
changes a score.

## Arithmetic and rounding

- All arithmetic is integer fixed-point in units of 1/10 000
  (`App\Services\Dna\FixedPoint`). No floating point is used anywhere, so
  results do not depend on the platform.
- Every division rounds half up to 4 decimal places. Scores are stored as
  `numeric(5,4)` and serialized as decimal strings (`"0.8125"`).
- Multiplications and additions are overflow-checked.
- The values in `dimensions` (`value`, `score`, `weight`,
  `effective_weight`, `contribution`, `data_quality`) are decimal strings
  with 4 places. Raw counts are kept as integers.

## Determinism

The same verified result scored with the same scoring version gives exactly
the same DNA:

- no clock, randomness, environment, locale, database order, network or AI
  in the engine;
- dimensions and components are processed in the specification's fixed
  order, so the order of keys in the input does not matter;
- only the inputs listed in the specification affect the score; other fields
  (averages, per-language groups, findings items, diagnostics) do not.

All of this is covered by tests (`CodeDnaScoringEngineTest`), including a
scan of the scoring code for clock, random, network and floating-point
calls. The JSONB column does not preserve object key order, so readers
address dimensions by identifier.

## Versioning

- `CODEDNA_SCORING_VERSION` (default and only supported value: `1.0.0`)
  selects the specification used for new snapshots. The configuration
  validator rejects unknown versions at boot.
- A version's meaning never changes. Any change to an input, weight,
  threshold, minimum, formula or rounding rule is a new version: patch for
  fixes that change no score, minor for recalibration, and major for new or
  removed dimensions. The fingerprint test enforces this.
- A snapshot is unique per `(analysis_run_id, scoring_version)`. A new
  version scores existing runs into **new** snapshots (`dna:score
  --missing`), and old snapshots stay as they are. Compare snapshots only
  within one `scoring_version` and `metrics_version`.

## Calculation

`CalculateDnaSnapshot::handle($analysisRunId)` runs in one transaction:

1. Locks the run row. The run must exist and be `SUCCEEDED`.
2. If a snapshot exists for (run, configured scoring version), returns it
   without reading or scoring anything (`created = false`).
3. Loads the stored result and requires a `static_analysis` result with
   metrics version `1.0`. It recomputes the result hash and checks that the
   result belongs to the run.
4. Scores the result. Ownership (`user_id`, `project_id`) and
   `source_snapshot_id` come from the run and its project, never from a
   caller.
5. Inserts the snapshot.

Concurrent calls serialize on the run lock. The unique constraint backs the
lock up: a losing insert returns the winner's snapshot. Tests use forked
processes to show that concurrent calls produce one snapshot.

Scoring is triggered by:

- **The analysis job.** After a `static_analysis` result is persisted, the
  job scores it. Scoring is best effort: a scoring failure is logged
  (`dna.scoring_failed` with a failure code) and never changes the run,
  which is already `SUCCEEDED`. Success logs `dna.scored`.
- **`php artisan dna:score {analysis_run}` or `dna:score --missing`.**
  Scores one run, or every `SUCCEEDED` `static_analysis` run that has no
  snapshot for the configured version. Use it for runs that succeeded before
  Phase 11, after a scoring failure, or after a version change. It is
  idempotent.

Failures (`DnaScoringFailure`):

- `RUN_NOT_FOUND`, `RUN_NOT_SUCCEEDED`
- `RESULT_MISSING`, `RESULT_TYPE_NOT_SCOREABLE`
- `METRICS_VERSION_UNSUPPORTED`
- `RESULT_INTEGRITY_FAILED`
- `RESULT_INVALID`

Their messages are fixed, safe texts: no IDs, hashes or result content.

Phase 11 added no HTTP endpoint. Since Phase 12 snapshots are readable
(never writable) through `GET /api/v1/projects/{project}/dna[/{snapshot}]`
([API](../api/README.md#dna)) and shown on the CodeDNA dashboard, under the
same owner-only rules as projects.

## Snapshot record

| Column | Value |
|---|---|
| `analysis_run_id`, `source_snapshot_id`, `project_id`, `user_id` | From the run |
| `analyzer_version`, `ir_version`, `metrics_version`, `contract_version` | From the stored result |
| `scoring_version` | `1.0.0` |
| `result_hash` | The verified result's hash |
| `status` | `READY` or `INSUFFICIENT_DATA` |
| `overall_score` | `0.0000`–`1.0000`, or `NULL` unless `READY` |
| `data_quality` | `0.0000`–`1.0000` |
| `dimensions` | Per dimension: `dimension`, `name`, `scoring_version`, `status`, `unavailable_reason`, `score`, `weight`, `effective_weight`, `contribution`, `data_quality`, `calculation`, and `components` (each with `status`, `weight`, `required`, `numerator`/`denominator` metric paths with their raw counts, `minimum_denominator`, `best`, `worst`, `value`, `score`) |
| `evidence` | `scoring_version`, `specification_fingerprint`, `source` (run, source snapshot, result type, result hash, metrics version), `aggregation`, `availability`, `data_quality` breakdown |
| `competencies`, `strengths`, `weaknesses` | `NULL` (interpretation is not scoring) |

Evidence references metric paths and their counts. It does not copy the
analyzer output.

## Examples

**A rateable project.** 40 functions, 10 types, 10 analyzable files (9
parsed, 1 with syntax errors), `complexity_total` 160, 2 functions over
complexity 10, and 1 function over 100 lines:

| Dimension | Components | Score | Effective weight | Contribution |
|---|---|---|---|---|
| COMPLEXITY | mean 4.0 → 0.75 (×0.50); 2/40 = 0.05 → 0.75 (×0.25); nesting 0 → 1 (×0.25) | 0.8125 | 0.4000 | 0.3250 |
| STRUCTURE | 1/40 = 0.025 → 0.75 (×0.40); parameters 0 → 1 (×0.30); types 0/10 → 1 (×0.30) | 0.9000 | 0.4000 | 0.3600 |
| CODE_HYGIENE | 1/10 = 0.10 → 0.60 | 0.6000 | 0.2000 | 0.1200 |

The result:

- `overall_score` = **0.8050**, status `READY`.
- `data_quality` = 0.5 × 0.9 + 0.25 × 0.8 + 0.25 × 1 = **0.9000**.

**The captured analyzer fixture.** 3 analyzable files (2 parsed, 1 with a
syntax error) and 3 functions:

- `COMPLEXITY` and `STRUCTURE` are `UNAVAILABLE` (`INSUFFICIENT_EVIDENCE`:
  3 < 5 functions).
- `CODE_HYGIENE` scores 0.0000 (1/3 of files have syntax errors).
- One scored dimension is below the minimum of 2, so the status is
  `INSUFFICIENT_DATA` and `overall_score` is `NULL`.
- `data_quality` = 0.5 × 0.6667 + 0.25 × 0.06 + 0.25 × 0.1429 = **0.3841**.

**Renormalization.** If the nesting findings are missing from a result,
`COMPLEXITY` is `UNAVAILABLE` (`MISSING`). `STRUCTURE` and `CODE_HYGIENE`
are rescaled to effective weights 0.6667 and 0.3333, and
`evidence.aggregation.renormalized` is `true`.

## Deferred

- Per-language scoring and language mix adjustments (C/C++ preprocessing
  gaps are not compensated).
- Calibration of thresholds against real project distributions. That would
  be a new scoring version.
- Any interpretation: competencies, strengths and weaknesses (Phase 13+),
  and AI explanations (Phase 15).
- An API and UI for DNA (Phase 12).
- Database triggers against raw-SQL updates of snapshots (the known gap in
  [data-model.md](data-model.md#immutability)).
