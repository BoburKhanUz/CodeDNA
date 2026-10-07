# Growth Tracking v1

Phase 18. Growth tracking shows how a project's code changed between two
**code assessments**: the newest one and the one immediately before it.
It is an **observation layer**. It reads stored deterministic snapshots
and compares them. It never analyzes, scores, assesses or plans anything.

- **Rules version:** `1.0.0` (`CODEDNA_GROWTH_RULES_VERSION`)
- **Rules fingerprint:** `b983b8b800846927fbcfed9cd806026c7994ca2a8c86ecd58d22dca021fdf321`
- **Code:**
  - `backend/app/Services/Growth/` (rules, assessment, engine, events)
  - `backend/app/Actions/Growth/CalculateGrowthSnapshot.php`
  - `GrowthController`
  - `frontend/src/components/growth/`
- **Decision:** [ADR-010](../decisions/ADR-010-growth-tracking.md)

## What growth is, and what it is not

Growth is a comparison of two compatible assessments of the same project:

```text
delta = current − previous
```

A higher value is better for CodeDNA scores and competency scores. A lower
value is better for skill gaps.

| Statement | Why |
|---|---|
| **Growth ≠ learning activity.** | Completed roadmap steps are self-reported. They are never growth evidence. |
| **Growth ≠ challenge completion.** | Passing an exercise says nothing about the project's code. |
| **Growth ≠ AI.** | No language model is involved anywhere in growth, and AI assessments are never read. |
| **Growth ≠ self-report.** | Profile data and anything a client sends are never read. |
| **No baseline ≠ zero.** | A first assessment has no growth. It is never compared with an assumed 0. |
| **Incomparable ≠ regression.** | Assessments measured with different versions are not compared at all. |
| **Insufficient evidence ≠ regression.** | Missing, unsupported or thin evidence never yields a change. |

Only a new code analysis can show change. The API and the page state
this on every growth view.

The only growth evidence is a successful analysis run and its stored
results:

- the DNA snapshot (Phase 11);
- the competency snapshot (Phase 13);
- the skill gap snapshot (Phase 14).

Growth duplicates none of the algorithms that produce them, and never
changes their output.

## Assessments and the baseline

An **assessment** is one skill gap snapshot together with its lineage:

- competency snapshot;
- DNA snapshot;
- analysis run;
- source snapshot.

All of them belong to the same project and owner. Its time is its analysis
run's `completed_at`.

The **baseline** of an assessment is the **immediately preceding**
assessment of the same project:

- the newest assessment whose successful analysis run completed earlier
  (ties are broken by run ID);
- never the oldest assessment, never a later one, and never another
  project's or user's;
- not skipped, even when it is incompatible. That gives `INCOMPARABLE`,
  not a comparison with something older.

| Situation | Growth snapshot status |
|---|---|
| The first assessment of a project | `NOT_ESTABLISHED`: "Baseline not established". No observations, no zero. |
| The baseline was measured with different versions | `INCOMPARABLE`. The differing fields are listed, with no observations and no delta. |
| A compatible baseline | `COMPARED`. One observation per metric. |

### Compatibility

Two assessments are compatible only when all of these are identical. They
are stored as `versions` and `previous_versions`.

| Field | Source |
|---|---|
| `dna_scoring_version`, `dna_specification_fingerprint` | DNA snapshot |
| `metrics_version` | DNA snapshot (analyzer metrics) |
| `competency_version`, `competency_specification_fingerprint` | competency snapshot |
| `skill_gap_version`, `skill_gap_specification_fingerprint` | skill gap snapshot |
| `target_profile`, `target_profile_version` | skill gap snapshot |

Same project and owner, and valid provenance, are enforced by the database
(see [Storage](#storage)).

## Rules 1.0.0

The rules are frozen as data (`GrowthRules::toArray()`) and fingerprinted
with SHA-256 over canonical JSON. The fingerprint contains no timestamp,
ID or environment value, and tests pin it.

| Rule | Value |
|---|---|
| Arithmetic | Integer fixed point with 4 decimal places. No floats. |
| Meaningful delta | **0.0500**, inclusive (an absolute delta of at least 0.0500 is meaningful). |
| Minimum evidence quality | **0.6000** on both sides, inclusive, where evidence quality is stored. |
| Measured states | DNA `SCORED` / `READY`; competency `ASSESSED`; skill gap `GAP` / `NO_GAP`. |
| Better direction | DNA and competency: higher. Skill gap: lower. |
| Gap transitions | `GAP → NO_GAP` = `IMPROVED`; `NO_GAP → GAP` = `REGRESSED`. |
| Level order | `NOT_ESTABLISHED < DEVELOPING < ESTABLISHED < STRONG` (Phase 13). |

**Why 0.0500.** It is the resolution CodeDNA already treats as material.

- Phase 14's material-gap threshold is 0.05: about one function in a
  hundred for a share component
  ([skill-gap-v1.md](skill-gap-v1.md#formulas)).
- Smaller differences are within what an ordinary edit moves, and
  reporting them as growth would be noise.
- Using the same bound keeps "a gap opened or closed" and "a meaningful
  change" on one scale.

**Why 0.6000.** It is Phase 14's bound for HIGH priority: below it, the
evidence is too thin to support a strong claim. Growth uses the same bound
before claiming a change.

### Statuses

Each observation gets exactly one status.

| Status | When |
|---|---|
| `INSUFFICIENT_EVIDENCE` | Either side is not in a measured state (including `MISSING`: the metric is absent from one assessment). Values and delta are null. **Or** either side's evidence quality is below 0.6000: the delta is kept and no change is claimed. |
| `IMPROVED` | A skill gap goes from `GAP` to `NO_GAP`; **or** the better-direction delta is at least +0.0500. |
| `REGRESSED` | A skill gap goes from `NO_GAP` to `GAP`; **or** the better-direction delta is at most −0.0500. |
| `UNCHANGED` | Both sides measured, and the difference is smaller than 0.0500. |

The checks run in that order. The order is chosen so that:

- thin evidence never closes a gap;
- an opened gap is a regression even if its raw difference is tiny;
- a value that moves from unmeasured to measured is never an improvement.

The following are therefore **not** improvement:

- `INSUFFICIENT_EVIDENCE → GAP`;
- `UNSUPPORTED → supported`;
- `MISSING → available`;
- `UNAVAILABLE → SCORED`.

The following are **not** regression:

- a dimension that becomes unavailable;
- the overall score becoming `INSUFFICIENT_DATA`.

### What is tracked

| Metric type | Keys | Value | Evidence quality | Level |
|---|---|---|---|---|
| `DNA` | `OVERALL`, then every stored dimension (sorted) | `overall_score` and dimension `score`, as stored | `data_quality` (overall) and dimension `data_quality` | — |
| `COMPETENCY` | every stored competency | `score`, as stored | `evidence_quality` | `level` |
| `SKILL_GAP` | every stored result | `raw_gap`, as stored | `evidence_quality` | `current_level` |

- `DNA OVERALL` is the existing Phase 11 overall score, not a new aggregate.
- Growth adds no dimension, competency or metric.
- There is **no combined growth score**. Summaries are counts per metric
  type and status, plus counts of level changes.

### Level transitions

For competencies with a level on both sides, `level_change` is `UP`,
`DOWN` or `SAME`. It is null otherwise.

A level transition is an **observation next to the score**, not a status
of its own. The score decides the status. A level boundary crossed by a
change of 0.0020 is `LEVEL_UP` with status `UNCHANGED`.

## Events

Events are derived deterministically from the stored observations when
they are read (`GrowthEvents`). They never contain generated text.

| Event | When |
|---|---|
| `GAP_CLOSED` / `GAP_OPENED` | A skill gap's state changed, and the status is `IMPROVED` / `REGRESSED`. |
| `IMPROVED` / `REGRESSED` | Any other observation with that status. |
| `LEVEL_UP` / `LEVEL_DOWN` | A competency level changed. |

## Generation

The analysis job (`AnalyzeSourceSnapshot`) runs these stages in order:

```text
analysis → DNA (Phase 11) → competencies (Phase 13) → skill gaps (Phase 14) → growth (Phase 18)
```

Growth runs last, **best effort**. A growth failure is logged as
`growth.failed` and never fails the run, or the DNA, competency or skill gap
snapshots.

`CalculateGrowthSnapshot::handle($skillGapSnapshotId)` runs in one
transaction:

1. Lock the skill gap snapshot row.
2. Return the existing growth snapshot for (assessment, rules version), if
   there is one.
3. Require a `SUCCEEDED` run with `completed_at`.
4. Find the baseline (see above).
5. Read both assessments' stored values (`GrowthAssessment`) and compare
   them (`GrowthEngine`).
6. Insert the snapshot and its observations.

The only input is a server-chosen ID. **Idempotent:** the row lock
serializes callers, and `UNIQUE (skill_gap_snapshot_id, rules_version)`
backs it up. A lost race returns the stored snapshot. This is tested with 2
and 8 concurrent workers.

### Recovery

```sh
php artisan growth:calculate <skill-gap-snapshot-id>
php artisan growth:calculate --missing
```

- `--missing` processes every assessment without a growth snapshot for the
  configured rules version.
- It goes oldest first, in the baseline's own order. Each line reads
  `<id> created|exists <STATUS>` or `<id> failed <CODE>`.
- Use it for:
  - assessments made before Phase 18;
  - after a failure;
  - after a rules version change.

## Activity context

The growth detail shows learning activity between the two assessments as
context only, under "Learning activity (context only)":

- roadmap steps completed;
- challenges passed.

Activity is counted when it is read, in the window
`(previous_assessed_at, assessed_at]`. It is never stored in growth and
never read by the engine. Without a baseline it is null.

Wording is never causal. Use "After the next code assessment…", and never
"Because you completed…".

## Versioning and provenance

- **Immutable.** A growth snapshot is never updated. A new rules version
  creates new snapshots next to the old ones, and old snapshots stay
  readable.
- **`rules.current`.** The API reports whether a snapshot uses the
  server's current rules version and fingerprint.
- **Stored with each snapshot:**
  - **Lineage:** the full lineage of both assessments (skill gap,
    competency, DNA, run and source snapshot IDs), and both assessment
    times.
  - **Rules:** the rules version and fingerprint.
  - **Versions:** both assessments' versions and specification
    fingerprints.
  - **Differences:** the differing fields, for `INCOMPARABLE`.
  - **Summary:** the categorical summary.
- **Stored with each observation:**
  - both states;
  - both values, and the delta;
  - both levels, and the level change;
  - both evidence qualities;
  - the better direction and the status.

## Storage

Two tables, created in one additive migration
(`2026_10_15_000001_create_growth_tables.php`). No source code is stored,
and no foreign key cascades.

**`growth_snapshots`**, one per (assessment, rules version):

- **Columns:**
  - the current lineage, and `previous_*` (nullable);
  - `assessed_at` and `previous_assessed_at`;
  - `rules_version` and `rules_fingerprint`;
  - `versions` and `previous_versions`;
  - `differences`, `status`, `summary`.
- **Composite foreign keys:** both lineages point at
  `skill_gap_snapshots_lineage_unique` (id, project, competency, DNA, run,
  source, user). A baseline must therefore be a real assessment of the same
  project and owner, and an assessment with growth cannot be deleted.
- **Checks:**
  - valid status;
  - the baseline is all-or-none;
  - there is a baseline ⇔ the status is not `NOT_ESTABLISHED`;
  - the baseline is strictly earlier;
  - `differences` is non-empty ⇔ the status is `INCOMPARABLE`;
  - the rules version and fingerprint have the right format;
  - JSON size is bounded.
- **Indexes:**
  - `(project_id, assessed_at)`;
  - `(user_id, created_at)`;
  - `previous_skill_gap_snapshot_id`;
  - unique `(id, project_id, user_id)`.

**`growth_observations`**:

- **Columns:** `growth_snapshot_id`, `project_id`, `user_id` (a composite
  foreign key onto the snapshot), `position`, `metric_type`, `metric_key`,
  `better`, both states, both values, `delta`, both levels,
  `level_change`, both evidence qualities, `status`.
- **Unique:** (snapshot, type, key) and (snapshot, position).
- **Checks:**
  - valid type and status;
  - direction (`LOWER` ⇔ `SKILL_GAP`);
  - values are all-or-none;
  - `delta = current − previous`;
  - a classified status needs a delta;
  - values are in 0–1;
  - valid level change.
- **Index:** `(project_id, metric_type, metric_key)`.

**Triggers:**

- `growth_refuse_update` rejects every `UPDATE` of either table ("growth
  history is immutable").
- `growth_observations_compared_only` rejects observations for a snapshot
  that is not `COMPARED`.

The models also refuse updates and deletes.

## API

Read-only and owner-only. See [API](../api/README.md#growth-tracking).

| Method and path | Returns |
|---|---|
| `GET /api/v1/projects/{project}/growth` | `state`, `notice`, `latest` (detail or null), `series` |
| `GET /api/v1/projects/{project}/growth/timeline` | Paginated growth snapshot summaries, newest assessment first |
| `GET /api/v1/projects/{project}/growth/{growthSnapshot}` | One growth snapshot in detail |

- **`state`** is one of `NO_ASSESSMENT`, `NOT_CALCULATED` (the newest
  assessment has no growth snapshot yet), `NOT_ESTABLISHED`,
  `INCOMPARABLE` or `COMPARED`.
- **`series`** follows the newest unbroken chain of `COMPARED` snapshots
  (at most 10): the first baseline's values, then each compared
  assessment. It never spans a missing baseline or a version change.
- **Archived projects** stay readable.
- **Other users** get the same `404 RESOURCE_NOT_FOUND` as elsewhere.
- **No write route.** There is no mutation route (405), and no query
  parameter chooses a snapshot, value, status, baseline or rules version.
- **Never returned:** storage URLs and keys, analyzer payloads, and owner
  IDs.

## Frontend

`/app/projects/[project]/growth`, linked from:

- the CodeDNA dashboard (card);
- the Competency Matrix, Skill Gaps, Learning Roadmap and Coding Challenges
  (navigation).

### States

- No assessment yet.
- Baseline not established.
- Changes since the previous assessment.
- No meaningful changes detected.
- Insufficient evidence.
- No comparable assessment, with the differing versions.
- Growth not calculated yet.
- Archived.
- Superseded: an earlier snapshot opened from the timeline.

### Sections

- **Summary:** counts only.
- **Assessments:** the two assessments compared, with links to their
  CodeDNA.
- **Changes:** the events.
- **Metric sections:** CodeDNA dimensions, competencies (with level
  transitions) and skill gaps ("lower is better").
- **Trend.**
- **Timeline.**
- **Learning activity:** context only, in its own dashed box.
- **Provenance.**

### Visuals

- **Before and after:** two neutral bars on the full 0–100 scale.
- **Trend:** a line only for three or more comparable assessments, on a
  fixed 0–100 axis. Two assessments are never drawn as a trend.
- **No invented values:**
  - unmeasured values show "No values compared", never a bar or a 0;
  - deltas are reformatted from the server's strings digit by digit;
  - nothing is compared in the browser.

## Security

- Owner-only through the project policy.
- Lineage foreign keys rule out cross-project and cross-user comparisons.
- No client-controlled input reaches the engine.
- No AI, and no network access.
- Responses carry IDs and stored values only.

## Limits and performance

- Calculation reads two assessments with a fixed number of queries.
- The overview and the timeline use a fixed number of queries, whatever
  the number of assessments (tested): observations are eager-loaded, and
  the activity is one query.
- The series is bounded to 10 snapshots, and the timeline is paginated
  (at most 100 per page).

## Not included

- GitHub, GitLab and Bitbucket integration, and historical DNA (Phase 19
  onward).
- Comparisons across projects or users, team growth, and benchmarks.
- A growth score, ranking or prediction.
- Notifications.
