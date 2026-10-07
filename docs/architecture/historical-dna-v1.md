# Historical DNA v1

Phase 20. Historical DNA answers one question: **what did this project's
analyzed code look like at each code assessment?** It is a **read model**
over the immutable snapshots that already exist. It never analyzes, scores,
assesses or stores anything.

- **Code:**
  - `backend/app/Services/History/` (reader, point, comparer)
  - `HistoryController`, `HistoryPointResource`
  - `frontend/src/components/history/`, `frontend/src/lib/history/`
- **Decision:** [ADR-012](../decisions/ADR-012-historical-dna.md)
- **Persistence:** none. No migration, no table, no index.

## Historical DNA is not growth tracking

| | Growth tracking (Phase 18) | Historical DNA (Phase 20) |
|---|---|---|
| Question | What changed between an assessment and the one before it? | What did the DNA look like at each assessment? |
| Shape | One comparison per assessment | The sequence of assessments, as recorded |
| Storage | `growth_snapshots`, `growth_observations` | None: reads existing snapshots |
| Comparison | Adjacent assessments, stored | Any two assessments chosen by the user, never stored |

Historical DNA consumes Phase 18. It links every point to its stored growth
snapshot and uses Phase 18's own engine and rules for comparisons. It is
not a second growth engine.

## Source of truth

```text
SourceSnapshot → AnalysisRun → DnaSnapshot → CompetencySnapshot → SkillGapSnapshot → GrowthSnapshot
```

Each layer is an immutable row created by its own engine. Historical DNA
reads them:

| Read | From |
|---|---|
| DNA overall score, data quality, dimensions, versions | `dna_snapshots` (Phase 11) |
| Competency scores, levels, evidence quality | `competency_snapshots` (Phase 13) |
| Gaps, targets, priorities, statuses | `skill_gap_snapshots`, `skill_gap_results` (Phase 14) |
| Growth status, summary, events | `growth_snapshots`, `growth_observations` (Phase 18) |
| Source provenance | `source_snapshots` (Phase 07, Phase 19 `metadata.provenance`) |
| Learning activity, as context | `roadmap_step_completions`, `challenge_submissions` |

Nothing is recalculated: not DNA scores, competency scores, levels, gaps,
priorities, targets or growth. Values pass through as the stored 4-place
decimal strings.

## Why no new persistence

Every capability the history needs is already stored:

- **History of each layer:** every layer is one immutable row per
  assessment and version. A new analysis or a new version adds rows; it
  never replaces old ones.
- **Lineage:** composite foreign keys already tie every layer to its
  project, owner, run and source snapshot.
- **Time:** `analysis_runs.completed_at`, the time Phase 18 already uses.
- **Versions and fingerprints:** stored on each snapshot.
- **Provenance:** stored on the source snapshot.
- **Indexes:** a page joins `dna_snapshots` on its `(project_id,
  created_at)` index to `analysis_runs` by primary key. Each layer is then
  loaded by its own existing unique lineage index.

A history table would copy every score a second time. That copy could
disagree with its source, and every future layer would need to keep it in
sync. Phase 20 adds no migration.

Immutability is enforced by the models (updates and deletes throw
`DomainRuleViolation`) and, for growth, by a database trigger. Database
triggers on the DNA, competency and skill gap tables would be stronger. They
are left as a future hardening item, because existing tests in earlier
phases deliberately tamper with those rows to simulate corruption.

## Points

A **point** is one DNA snapshot of the project that meets all of these:

- it belongs to the project and the project's owner;
- its analysis run is `SUCCEEDED` and has `completed_at`;
- it has its source snapshot and a scoring version (both required columns).

Queued, running, failed and cancelled analyses never appear. Neither do AI
assessments, roadmap or challenge activity.

Each point carries the layers derived from its DNA snapshot:

- the newest competency snapshot of that DNA snapshot;
- the newest skill gap snapshot of that competency snapshot;
- that assessment's growth snapshot under the server's current growth rules.

A missing layer is **unavailable**, not removed. A DNA snapshot without a
competency matrix is still a point. Its `layers.competency` and
`layers.skill_gaps` are `UNAVAILABLE`, and `competency`, `skill_gaps` and
`growth` are null.

**Order:** newest first, by `analysis_runs.completed_at`, then run ID, then
DNA snapshot creation time and ID. This is the order Phase 18 uses for
baselines. Re-scoring an old run under a later scoring version places the
new point at the run's time, in its own segment.

**Projects are never combined.** History is project-scoped. A developer
with several projects has several histories, and no developer-wide DNA is
derived.

## Values

| Field | Rule |
|---|---|
| `dna.dimensions` | The v1 dimensions in a fixed order (`COMPLEXITY`, `STRUCTURE`, `CODE_HYGIENE`), then any other stored ones. A dimension the snapshot does not contain is `MISSING`, with null score. Missing is never 0 |
| `competency.competencies` | Stored `score`, `level` and `evidence_quality`. A level is categorical; only the stored score is ever plotted |
| `skill_gaps.results` | Stored status, current and target score, `gap` (the stored `raw_gap`), `material_gap`, priority and evidence quality. An unmeasured result has null gap and priority |
| `growth` | The stored growth snapshot's status, summary and events (Phase 18), or null when none was calculated |

**Resolved gaps stay visible.** An earlier point's `GAP` is that point's
stored row. A later `NO_GAP` never replaces it, and the later point's growth
events record `GAP_CLOSED`.

## Version segments

Every point has `versions` (the Phase 18 compatibility fields) and
`segments`, one opaque key per layer:

| Key | Covers |
|---|---|
| `segments.dna` | DNA scoring version, scoring specification fingerprint, metrics version |
| `segments.competency` | The DNA fields, plus the competency version and fingerprint (null without the layer) |
| `segments.skill_gaps` | Every compatibility field (null without the layer) |

Points with equal keys were measured alike for that layer. The page groups
consecutive equal keys into **segments**. A trend line is drawn only:

- inside one segment;
- between two or more consecutive points that all have a value.

A version change or a missing value breaks the line. It is never bridged.
One point is a baseline, not a trend.

## Comparison

`GET /history/compare?from=&to=` compares any two points of the project.
The client sends two DNA snapshot IDs. The server:

1. finds both among the project's eligible points (otherwise `404`, like a
   missing one), and orders them by time;
2. compares only the layers both points have. Any other layer is
   `UNAVAILABLE`;
3. returns `INCOMPARABLE`, with the differing fields and no observations,
   if any compared layer differs in any compatibility field;
4. otherwise returns `COMPARED`, with Phase 18 observations.

| `basis` | When | Values |
|---|---|---|
| `GROWTH_SNAPSHOT` | The later point's stored growth (current rules) has exactly the earlier point as its baseline | The stored growth snapshot, as is |
| `GROWTH_RULES` | Any other pair | Phase 18's `GrowthEngine` with the current rules over the two points' stored values, in memory. Nothing is stored |

For adjacent points the two bases give identical results (tested).
Comparing never creates a growth snapshot.

Learning activity between the two points is returned as `activity`, as
context only.

## Provenance

| `source.origin` | When | Shown |
|---|---|---|
| `UPLOAD` | Uploaded archive | Source snapshot version, hash, file count, language |
| `GITHUB` | A Phase 19 import (`metadata.provenance.provider = github`) | The same, plus `github.repository`, `github.ref`, `github.commit_sha` |
| `REPOSITORY` | Any other repository source | The same as an upload |

GitHub fields are validated before they are returned: the `owner/name`
shape, a ref without control characters, and a 40-character lowercase SHA.
Anything else is null. Never returned:

- installation or repository IDs, and import IDs;
- tokens;
- GitHub API or archive URLs;
- storage disks, keys or URLs;
- owner IDs, analyzer payloads and result hashes.

## Learning activity

The point detail returns `activity` between the previous point and this
one; a comparison returns it between its two points. It counts roadmap
steps completed and challenges passed in `(earlier, later]`, through the
same helper growth uses (`LearningActivity`).

Activity is context only. It is never history or growth evidence, never
changes a value, and is never described as a cause.

## API

Read-only and owner-only. See [API](../api/README.md#historical-dna).

| Method and path | Returns |
|---|---|
| `GET /api/v1/projects/{project}/history` | Points, newest first. `?page`, `?per_page` (default 25, at most 100) |
| `GET /api/v1/projects/{project}/history/{dnaSnapshot}` | One point, `previous` and `next`, `activity`, `notice` |
| `GET /api/v1/projects/{project}/history/compare?from=&to=` | A comparison |

- Any query field other than those listed answers `422`, so scores,
  deltas, versions and statuses can never come from a client.
- Another user's project, or a snapshot of another project, answers
  `404 RESOURCE_NOT_FOUND`.
- Archived projects stay readable.
- There is no write method (`405`).

## Frontend

`/app/projects/[project]/history`, linked from the CodeDNA dashboard and
from growth.

- **Header:** the notice, and an archived note when the project is archived.
- **Latest and previous assessment.** With one assessment: "Baseline
  established".
- **DNA evolution:** one line per dimension on a fixed 0–100 axis, oldest on
  the left. Dimensions have fixed colors and distinct marker shapes, a
  legend and per-point tooltips. A dashed divider marks a scoring segment
  change, and no line crosses it. With no drawable line: "No historical
  trend available".
- **Version segments:** the scoring version, the number of assessments and
  the date range of each segment.
- **Competency evolution:** a table of stored scores and levels per
  assessment, and the level transitions recorded by growth.
- **Skill-gap evolution:** a table of each competency's stored state, gap
  and priority per assessment. A gap closed according to growth is marked
  "Resolved since the previous assessment". Gaps are never merged into an
  overall gap.
- **Assessment timeline:** newest first. Each entry shows dimension scores,
  data quality, the scoring version, provenance, and a summary of its
  growth with a link. Two entries can be selected for comparison.
- **Comparison:** the server's status, observations and deltas, the layers
  that are unavailable, and activity in a dashed "context only" box. An
  `INCOMPARABLE` comparison lists the differing versions and shows no
  delta.
- **Pagination:** 25 per page.

The browser orders, groups and draws server values. It never computes a
score, delta, gap or compatibility.

## Performance

- A page is bounded (at most 100 points). It uses 9 queries whatever its
  size: the count, the page, runs, sources, competencies, skill gaps, their
  results, growth snapshots and their observations.
- The detail adds two keyset queries (previous and next) and one activity
  query.
- A comparison reads exactly the two requested points and their layers.
- A test proves that the number of queries does not grow with the history
  length.

## AI

None. No language model is used for history, trends, comparison,
compatibility or events, and no source code is read.

## Not included

- Developer-wide DNA across projects.
- Database-level immutability triggers on the DNA, competency and skill gap
  tables (future hardening).
- Comparisons across projects or users, team history, and benchmarks.
- Any change to Phase 18 growth or Phase 19 GitHub behavior.
