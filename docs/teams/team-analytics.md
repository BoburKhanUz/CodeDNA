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

Every figure is a single SQL aggregate (`DISTINCT ON` per project, then
`GROUP BY`). The query count is constant (tested with 2 and 5 projects), and
no snapshot is loaded into PHP memory. The scans are bounded by the
organization's projects and use the existing `project_id, created_at`
indexes. Very large organizations would need a stored, incrementally updated
team read model; that, and any "team DNA snapshot", is a Phase 25+ concern.
