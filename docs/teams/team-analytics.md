# Team analytics (Phase 24)

`GET /api/v1/organizations/{organization}/analytics` (any active member)
returns deterministic, read-only figures over the organization's projects.
They are computed by `App\Services\Organizations\TeamAnalytics`.

## What it is not

- **Not a scoring engine.** Nothing is scored, recalculated or interpreted,
  and no AI is involved.
- **Not a writer.** No DNA, competency, skill gap, growth or analysis record
  is created, changed or removed (tested).
- **Not a view of people.** Members' personal projects are never read.
  There is no per-person score, and joining a team changes nobody's own
  CodeDNA.

## Semantics

| Figure | Source | Rule |
|---|---|---|
| Members | `organization_memberships` | ACTIVE by role; SUSPENDED count |
| Seats | billing account | limit, used (ACTIVE), remaining |
| Projects | `projects` | the organization's ACTIVE and ARCHIVED |
| Analyses | `analysis_runs` | succeeded, failed, succeeded in the last 30 days, last completion; all the organization's projects |
| DNA | latest `dna_snapshots` row of each ACTIVE team project | grouped by **scoring version**; average overall score per version |
| Members with DNA | the same | ACTIVE members who created a team project that has DNA |
| Competencies | latest `skill_gap_snapshots` row of each ACTIVE team project, with its `skill_gap_results` | grouped by **competency, skill gap and target profile versions**; per competency: projects measured, average current score, projects with a gap, priorities, projects with insufficient evidence; snapshot status counts |

Rules that keep the figures honest:

- **Versions are never mixed.** Results from different versions are reported
  side by side as separate groups and never averaged together.
- **Thin evidence gives no average.** An average is shown only when at least
  `minimum_projects` (2) projects have measured evidence for it. Otherwise it
  is `null` with `sufficient: false`, and the UI says "not enough evidence".
- **Only measured results count.** A competency counts as measured where the
  skill gap result is `GAP` or `NO_GAP`. `INSUFFICIENT_EVIDENCE`, `MISSING`
  and `UNSUPPORTED` results are counted separately, never as zero.
- **Latest snapshot only.** Each project contributes its newest snapshot (by
  creation time, then ID), so no project is counted twice.

## Performance

Every figure is a single SQL aggregate. The query count is constant
(`QueryBudgetTest`), and no snapshot is loaded into PHP memory.

Since Phase 26, each active team project's latest DNA, competency and
skill-gap snapshot is found with **one index probe per project**: a
`LATERAL … ORDER BY created_at DESC, id DESC LIMIT 1` on the
`(project_id, created_at)` indexes, inside a `MATERIALIZED` CTE shared by
the figures. The earlier `DISTINCT ON` form sorted the whole snapshot
tables. The figures are unchanged (output compared byte for byte on 31
organizations), and the cost now follows the organization's own projects,
not the platform's data.

| Organization | Before | After (p50 / p95) |
|---|---|---|
| 2,000 members, 2,000 projects (medium benchmark) | 592 / 687 ms | 84 / 127 ms |

Organizations past ~5,000 active projects would need a stored,
incrementally updated team read model
([scaling guide](../performance/scaling-guide.md#postgresql)). Any "team
DNA snapshot" is out of scope.
