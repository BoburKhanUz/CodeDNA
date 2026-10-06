# MVP Definition

The MVP proves the core loop end to end on **real analysis**, with no fake
metrics, fake analysis, or placeholder scores presented as real.

## User journey

```text
Register / log in ─► create project ─► upload source (ZIP) ─► start analysis
    ─► see status (QUEUED → RUNNING → SUCCEEDED | FAILED)
    ─► view DNA: overall score + dimensions, with evidence and versions
    ─► re-run, or upload a new snapshot and analyze again
```

## In scope

| Area | Scope |
|---|---|
| Accounts | Register, log in, log out, current user; basic developer profile |
| Projects | Create, list, view, rename, archive (Phase 07). Deletion, which also removes stored source, comes with the purge workflow |
| Source input | ZIP upload into an `UPLOAD` project, stored as an immutable source snapshot (Phase 07) |
| Languages | PHP, Python, JavaScript, TypeScript, delivered in that order. A language is listed as supported only once its parser and tests are in. |
| Analysis | Asynchronous pipeline via queue → internal analyzer; visible status; visible failures with a reason; re-run |
| Metrics | Deterministic static metrics ([metrics-v1.md](../architecture/metrics-v1.md)) |
| DNA | Deterministic, versioned scores for the scoring 1.0 dimensions ([ADR-004](../decisions/ADR-004-dna-scoring.md)) |
| Dashboard | Latest DNA, per-dimension scores with statuses, key metrics, analysis history list |
| Security | Secret detection (locations only), ignore rules, size, file and time limits, no code execution |

## Out of scope for the MVP

GitHub/GitLab integration, competencies, skill gaps, AI interpretation,
challenges, learning roadmaps, historical trend analytics beyond a simple
list of past snapshots, teams, organizations and billing.

## Success criteria

The MVP is complete only when all of these hold, verified by automated tests
where marked (T):

1. A developer can create an account and log in. (T)
2. A developer can create a project. (T)
3. A developer can upload source code as a ZIP. (T)
4. A developer can start an analysis, and the HTTP request returns
   immediately. (T)
5. Processing status is visible and updates until the run is terminal. (T)
6. The analyzer parses supported source files in all four MVP languages
   (PHP, Python, JavaScript, TypeScript). (T)
7. Metrics are generated from the IR. (T)
8. Metrics, features and versions are stored. (T)
9. The DNA score is calculated deterministically: the same snapshot and
   versions give the same `result_hash`. (T)
10. The dashboard shows the stored result, with no hardcoded values. (T, E2E)
11. An analysis can be repeated as a new run. (T)
12. Failed analyses are visible, with a user-safe reason. (T)
13. Tests cover the critical pipeline: upload → queue → analyzer (contract
    fixtures) → persistence → API → UI. (T)

## Limits (MVP defaults)

These are configurable via environment; see the
[internal contract](../api/internal-analyzer-contract.md#7-timeouts-retries-and-limits).
ZIP ≤ 50 MiB compressed / 200 MiB extracted, ≤ 20 000 files, files > 1 MiB
skipped, analysis hard limit 240 s.

## Known MVP limitations (shown in the UI)

- **Authorship:** uploaded code is attributed to the uploader by their own
  declaration (ADR-004 open question).
- Some dimensions are `not_assessed` in scoring 1.0 (architecture,
  maintainability), and others can be `insufficient_data` for small
  codebases.
- Syntax-level analysis only. There is no type or cross-file semantic
  resolution (ADR-002).
