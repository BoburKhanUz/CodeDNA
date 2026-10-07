# Learning Roadmap v1

- **Phase:** 17
- **Versions:** roadmap catalog `1.0.0`, roadmap rules `1.0.0`, track schema
  `roadmap-track/1`
- **Decision:** [ADR-009](../decisions/ADR-009-learning-roadmap.md)
- **Related:**
  - [skill-gap-v1.md](skill-gap-v1.md), where the gaps come from
  - [coding-challenges-v1.md](coding-challenges-v1.md), for practice
  - [API](../api/README.md#learning-roadmaps)
  - [frontend](frontend.md#learning-roadmap-appprojectsprojectroadmap)

A learning roadmap answers one question: **what should the developer work
on next?** It is generated deterministically from a project's newest skill
gap analysis. It holds:

- a **development focus**: which measurable gaps to work on, in which
  order, and why;
- one **learning track** per focus competency: short, ordered steps that
  end with a coding challenge and a re-assessment.

## Roadmap ≠ assessment

The roadmap is a **planning layer**. It reads stored skill gaps and never
writes analysis data:

```text
Skill gap snapshot ──(read only)──► Development focus ──► Learning tracks ──► Steps ──► Practice (challenges)
                                                                                    └──► Re-assessment: a NEW analysis
```

- **Learning completion ≠ skill improvement.** Completing a step, a track
  or a whole roadmap is self-reported progress. It never changes a DNA
  score, a competency score or level, a skill gap, a priority, a target, a
  snapshot or an analysis result.
- **No automatic effects.** No step completion closes a gap or promotes a
  competency.
- **Only new code analysis** can change CodeDNA's deterministic evidence.
  The re-assessment step only tells the developer to run one; it never
  starts one.

The API (`notice`) and the UI say so on every roadmap:

> Completing learning steps does not change your CodeDNA score or skill
> gap. Improvement is measured through new code analysis.

A test compares the skill gap, competency, DNA and run rows before and
after a roadmap is generated and every step completed
(`RoadmapApiTest`). There is no AI anywhere in the roadmap: everything
works with `AI_ENABLED=false`, and the tests bind an AI provider that
fails if it is ever called.

## Catalog

The catalog is server-owned, versioned and validated at load
(`App\Services\Roadmap\RoadmapCatalog`).

- **Files.** `backend/resources/roadmaps/v1/<COMPETENCY>.json`, one track
  per measurable competency, validated against
  `roadmap-track-1.schema.json`.
- **Tracks only for measured competencies.** The four tracks are
  `COMPLEXITY_MANAGEMENT`, `FUNCTION_DESIGN`, `TYPE_STRUCTURE` and
  `CODE_HYGIENE`, the only competencies with measurable evidence. There are
  no tracks for testing, security, naming, performance, documentation or
  architecture.
- **Track fields:** key (the competency), version, title, description,
  objective, estimated minutes (the sum of its steps), ordered steps.
- **Step fields:** key, type, title, description, objective, estimated
  minutes, prerequisite steps.
- **Step types:**

  | Type | Meaning |
  |---|---|
  | `READ` | Understand a concept. |
  | `PRACTICE` | Apply it to your own code. |
  | `CHALLENGE` | Practise on a Phase 16 coding challenge of the competency. |
  | `REASSESS` | Run a new CodeDNA analysis (instruction only). |

- **Checks at load.** Besides the schema, the catalog refuses a track
  unless:
  - the file is named after the key, and the key is the competency;
  - every step key is unique and starts with the competency's prefix
    (`cm-`, `fd-`, `ts-`, `ch-`);
  - prerequisites name earlier steps of the same track;
  - there is exactly one `CHALLENGE` step, and the challenge catalog has a
    challenge for the competency;
  - the last step is the only `REASSESS` step, and it depends on every
    other step;
  - the track's estimate is the sum of its steps;
  - it has at most the rules' step limit (8).
- **Content.** The steps are concise and practical, and refer to what
  CodeDNA measures (cyclomatic complexity above 10, functions over 100
  lines, more than 5 parameters or nesting deeper than 4, types over 500
  lines, files with syntax errors). There are no links, no external
  content, no lessons and no generated text.
- **Clients never supply content.** The client cannot send tracks, steps,
  targets, competencies, gaps, versions, URLs or commands. Every write
  request body must be empty.

| Track | Steps | Estimate |
|---|---|---|
| COMPLEXITY_MANAGEMENT: Manage branching complexity | 7 | 3 h 50 min |
| FUNCTION_DESIGN: Design focused functions | 8 | 4 h 20 min |
| TYPE_STRUCTURE: Structure types around one responsibility | 6 | 3 h 50 min |
| CODE_HYGIENE: Keep source code parseable | 6 | 1 h 50 min |

## Rules

`App\Services\Roadmap\RoadmapRules` 1.0.0:

| Rule | Value |
|---|---|
| Actionable statuses | `GAP` only |
| Focus order | priority (HIGH, MEDIUM, LOW), raw gap (largest first), evidence quality (highest first, missing last), competency key |
| Maximum tracks | **3** |
| Maximum steps per track | **8** (enforced on the catalog) |
| Challenge recommendation | `challenge-selection/1.0.0`, with no history |

## Development focus

`App\Services\Roadmap\DevelopmentFocusResolver` is a pure function of the
skill gap results, the catalog and the rules. It uses no clock, no
randomness and no network.

1. **Actionable.** A result is actionable when its status is `GAP` and the
   catalog has a track for its competency.
2. **Not actionable.** Every other result is excluded, with a reason. No
   evidence is never presented as a learning need.

   | Reason | Meaning |
   |---|---|
   | `NO_GAP` | Measured, but no material gap. |
   | `INSUFFICIENT_EVIDENCE` | Not enough evidence to establish a gap. |
   | `UNSUPPORTED` | The analyzer cannot measure it in these languages. |
   | `MISSING` | The evidence is not available. |
   | `NOT_TARGETED` | The target profile sets no target. |
   | `NO_TRACK` | There is no learning track for the competency. |

3. **Order.** Priority (HIGH, MEDIUM, LOW), then raw gap (largest first),
   then evidence quality (highest first; missing ranks last), then
   competency key. Phase 14's priority already caps HIGH on thin evidence;
   the roadmap reuses it and adds no score of its own.
4. **Bound.** The first three actionable gaps are the focus. Further ones
   are kept in the roadmap as excluded with `TRACK_LIMIT`, ranked, for
   later.

Each entry stores the gap exactly as the skill gap snapshot recorded it:

- competency, status, priority and whether it was capped;
- current score, target score, raw gap, evidence quality and level.

Each focus entry also stores its `rank` and `deciding_criterion`: the first
criterion (`PRIORITY`, `RAW_GAP`, `EVIDENCE_QUALITY` or `COMPETENCY_KEY`)
on which it ranks above the next actionable gap. The UI turns this into
"Ranked above Function design because of a larger gap."

## Generation

`App\Actions\Roadmap\GenerateRoadmap` runs explicitly:
`POST /projects/{project}/roadmaps`, with an empty body.

1. Locks the project. An archived project gets `PROJECT_ARCHIVED`.
2. Takes the project's **newest** skill gap snapshot. Without one, it
   answers `ROADMAP_NO_SKILL_GAPS`.
3. **Idempotency.** If a roadmap for that snapshot and the current roadmap
   and rules versions exists, it is returned (200,
   `Idempotent-Replayed: true`), whatever its status.
4. **Snapshot check.** The snapshot's stored specification fingerprint and
   target profile must match its skill gap version. Otherwise it answers
   `ROADMAP_EVIDENCE_INVALID`; nothing is reinterpreted.
5. **Generation.** `App\Services\Roadmap\RoadmapGenerator` resolves the
   focus. With no actionable gap it answers `ROADMAP_NO_ACTIONABLE_GAPS`,
   and an existing roadmap is left as it is. Otherwise it copies every
   step of each focus track, numbered in learning order across tracks, and
   resolves the challenge reference of each `CHALLENGE` step.
6. **Superseding.** The project's previous `ACTIVE` roadmap becomes
   `SUPERSEDED`, pointing to its successor. The new roadmap and its steps
   are stored.

The roadmap never changes when the underlying data changes. A newer skill
gap analysis can produce a new roadmap only when the developer generates
it ("Update roadmap"), and the old one stays readable.

**Concurrency.** The project row lock serializes generation. Two database
constraints back it up: the unique key (skill gap snapshot, roadmap
version, rules version) and the partial unique index of one `ACTIVE`
roadmap per project. Tests fork 8 processes and get one roadmap, and race
superseding against completion.

## Statuses

| Status | Meaning |
|---|---|
| `ACTIVE` | The project's current roadmap; steps can be completed. |
| `COMPLETED` | Every step was marked done. Learning progress only: it does not mean a competency improved or a gap closed. |
| `SUPERSEDED` | A roadmap for a newer skill gap analysis replaced it. |

`COMPLETED` and `SUPERSEDED` are final. A database trigger enforces this,
and also keeps the roadmap's content, versions, fingerprints and lineage
from ever changing. Nothing is deleted.

## Progress

`POST /projects/{project}/roadmaps/{roadmap}/steps/{step}/complete`, with
an empty body:

- **Insert-only.** It inserts a completion row, so `completed_at` is the
  server's time.
- **Idempotent.** A step that is already completed answers 200 with
  `Idempotent-Replayed: true`.
- **Refusals.**
  - An archived project answers `PROJECT_ARCHIVED`.
  - A roadmap that is not `ACTIVE` answers `ROADMAP_NOT_ACTIVE`.
  - A step whose prerequisites are not all completed answers
    `ROADMAP_STEP_PREREQUISITES_INCOMPLETE`.
  - An unknown step answers 404.
- **Completion.** Completing the last step makes the roadmap `COMPLETED`.
- **No undo.** Completion is final in v1.
- **No effect on assessment.** It changes no score, competency, gap,
  priority or snapshot.

## Challenge integration

A `CHALLENGE` step references the existing Phase 16 challenge catalog by
key and version. It is never a copy of a challenge.

- **Which challenge.** The recommendation is what the Phase 16 challenge
  selector chooses for that one gap with no project history: a BEGINNER
  exercise for HIGH and MEDIUM gaps, and an INTERMEDIATE one for LOW gaps,
  or the nearest difficulty. Because no history is used, the roadmap stays
  a function of the skill gap snapshot.
- **Missing challenge.** If the challenge catalog has no challenge for the
  gap at generation time, the step has no reference, and the UI says so.
  If the referenced challenge later leaves the challenge catalog, the API
  reports `in_catalog: false`.
- **Practice link.** The roadmap detail links the step to the developer's
  newest challenge for that skill gap snapshot and competency, if one was
  assigned. Otherwise it links to the Coding Challenges page, which may
  choose another challenge of the same competency when this one was
  already used.
- **No gap closure.** Passing a challenge never closes a gap.

## Re-assessment

Every track ends with a `REASSESS` step: upload the changed source and run
a new CodeDNA analysis. It is instructional only: Phase 17 starts no
analysis and changes no snapshot. The next skill gap analysis, and the
roadmap generated from it, show what actually changed.

## Versions and fingerprints

Each fingerprint is the SHA-256 of canonical JSON
(`App\Services\Analyzer\CanonicalJson`), and contains no IDs, timestamps
or environment values.

| Fingerprint | Covers | 1.0.0 |
|---|---|---|
| Catalog | the version, and every track's key, version and full content | `937b1e7f209819a06377d009112a6f003ac6c462ddab6b9a3ae804b75e759e63` |
| Rules | the full rules definition | `eeac05dd35054808855ae1446bf1ef4e943a173a4b4b624b909a6cb6c6dbd60f` |
| Roadmap | the generated content: versions, catalog, rules and challenge catalog fingerprints, the skill gap version, specification fingerprint and target profile, the focus, tracks and steps | per roadmap |

- **Pinned.** Tests pin the catalog and rules fingerprints, and show that
  any change to content or rules changes them.
- **Reproducible.** A test regenerates a stored roadmap from its skill gap
  snapshot and gets the same fingerprint.
- **No silent changes.** Any change to a track or rule needs a new version.
  Stored roadmaps copy their steps, so they stay readable and unchanged
  whatever happens to the catalog. Their detail reports whether the server
  still uses the same catalog and rules (`current`).
- **Configuration.** `CODEDNA_ROADMAP_VERSION` and
  `CODEDNA_ROADMAP_RULES_VERSION` (both only `1.0.0`) are validated at
  boot.

## Storage

The three tables are described in
[data-model.md](data-model.md#roadmap_snapshots-roadmap_steps-roadmap_step_completions).

- **`roadmap_snapshots`.** Lineage is one composite foreign key onto the
  skill gap snapshot's lineage index: the skill gap, competency and DNA
  snapshots, run, source snapshot, project and owner can never disagree.
- **`roadmap_steps`.** Immutable copies of the catalog steps.
- **`roadmap_step_completions`.** Insert-only, one row per completed step.

## Limits and performance

- **Bounded size.** A roadmap has at most 3 tracks of at most 8 steps (24
  steps).
- **Bounded reads.** Reading a roadmap takes a fixed number of queries,
  whatever its size (tested): roadmap, steps, completions, and challenge
  practice. The list counts completions inside its own query.
- **No queue, no cache.** Generation is a single short transaction.

## Not included

These are left for later phases:

- growth tracking and history analytics (Phase 18);
- automatic generation after each analysis;
- undoing a completion;
- reminders, notifications, calendars;
- courses, external resources, certificates;
- AI-written roadmaps or lessons;
- user or team targets;
- tracks for competencies CodeDNA does not measure.
