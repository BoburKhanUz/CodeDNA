# Backend Architecture (Laravel)

Laravel is the product and business backend. It is **not implemented yet**:
Phase 03 creates the application, and Phases 05–07 and 10 add the domain.

Related: [ADR-001](../decisions/ADR-001-stack.md),
[ADR-005](../decisions/ADR-005-service-communication.md),
[ADR-006](../decisions/ADR-006-authentication.md),
[API conventions](../api/README.md), [Data flow](data-flow.md).

## Responsibilities

Laravel owns users, authentication, profiles, projects, repositories and
provider integrations, source snapshots, analysis orchestration and status,
result persistence, DNA snapshots, and later competencies, skill gaps,
learning data, organizations, teams and billing. It also owns authorization,
rate limiting and every business rule.

**Laravel never parses or analyzes source code.** It validates uploads (type,
size, archive sanity), stores them, and hands them to the analyzer
(ADR-005).

## Structure

The project uses the standard Laravel layout, organized by domain where it
helps, without inventing a framework on top:

```text
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/V1/   # thin controllers, one per resource
│   │   ├── Requests/             # FormRequest validation
│   │   ├── Resources/            # API resources (response shape)
│   │   └── Middleware/           # request ID, etc.
│   ├── Models/
│   ├── Policies/                 # authorization per model
│   ├── Actions/                  # single-purpose domain operations (e.g. CreateAnalysisRun)
│   ├── Jobs/                     # RunAnalysisJob
│   ├── Services/Analyzer/        # AnalyzerClient (HMAC signing, response verification, error mapping)
│   ├── Services/SourceProviders/ # provider abstraction: Upload now; GitHub/GitLab later
│   └── Console/                  # scheduled commands (stale run sweeper)
├── routes/
│   ├── api.php                   # mounts /api/v1 routes
│   └── api_v1.php
├── database/{migrations,factories,seeders}
└── tests/{Unit,Feature}
```

Rules:

- **Controllers are thin.** They validate (FormRequest), authorize (Policy),
  call an Action, and return a Resource.
- **Actions** hold business operations and are unit-tested.
- **`AnalyzerClient`** is the only code that talks to the analyzer. It owns
  signing, timeouts, response verification and the mapping of contract error
  codes to retryable or non-retryable outcomes.
- **Source providers** implement one interface: produce an immutable source
  snapshot from a repository reference. `UploadProvider` comes first.
  GitHub and GitLab come in Phase 19. Provider tokens never reach the
  analyzer.
- Fake data is never seeded in non-test environments. Factories exist for
  tests only.

## Data model (MVP direction; finalized in Phase 05)

All tables use ULID primary keys and `timestamptz` timestamps.

| Table | Purpose | Phase |
|---|---|---|
| `users` | Accounts | 06 |
| `profiles` | Developer profile data (1:1 with users) | 06 |
| `projects` | User-owned project containers | 07 |
| `repositories` | Source origins (`provider`: `upload` for now) | 07 |
| `source_snapshots` | Immutable archives: object key, SHA-256, size, commit SHA (nullable), origin | 07 |
| `analyses` | A request to analyze a snapshot | 10 |
| `analysis_runs` | Executions: status, attempts, versions, request ID, failure info, timestamps | 10 |
| `analysis_metrics` | Metrics per run (overall and per language; JSONB plus key columns needed for queries) | 10 |
| `analysis_features` | Feature vector per run (JSONB) | 10 |
| `analysis_findings` | Secret and parse findings per run (locations only) | 10 |
| `dna_snapshots` | Immutable DNA result per completed run: scoring version, overall score, per-dimension status and score | 11 |

The master instruction's `repository_files` and `developer_dna` tables are
deliberately **not planned for the MVP**:

- Per-file metrics are not needed yet and would store a lot of file-path
  data.
- Developer-level DNA is derived from `dna_snapshots`
  ([ADR-004 §7](../decisions/ADR-004-dna-scoring.md#7-developer-level-dna-proposal)).
  It may be materialized later if performance requires.

Tables for competencies, skills, assessments, learning, organizations and
billing are created only in their own phases.

Snapshot immutability is enforced in the application (no update paths). A
database trigger rejecting `UPDATE` on `dna_snapshots` may be added in
Phase 05.

## Queue and scheduling

- `QUEUE_CONNECTION=redis`. A dedicated `analysis` queue keeps long jobs from
  blocking short ones.
- `RunAnalysisJob` settings: `$tries = 3`, `$timeout = 330`, backoff of
  `[30, 120]` seconds, and `failOnTimeout`. It only retries errors classified
  as retryable (see the contract).
- The `analysis` queue connection uses `retry_after = 360`.
- The scheduler runs the stale-run sweeper every 5 minutes.

## Security

- Authorization policies apply to every resource. A user can only access
  their own projects, repositories, snapshots, analyses and DNA.
- Uploads are restricted to ZIP files, with a maximum size
  (`ANALYZER_MAX_ARCHIVE_BYTES`, enforced in Laravel too) and MIME and magic
  byte checks. Original filenames are sanitized and never used as storage
  keys.
- Rate limits apply to auth, upload and analysis creation.
- Secrets come only from the environment. `APP_DEBUG=false` outside local
  development.
- Logs never contain source code, secret values, pre-signed URLs or HMAC
  material.

## Testing

- **Feature tests** for every endpoint: authentication, authorization (other
  users' resources return 404), validation and response shape.
- **Unit tests** for Actions, `AnalyzerClient` signing and verification
  against shared test vectors, error classification and state transitions.
- **Pipeline tests:** the analyzer is faked at the HTTP layer using contract
  fixtures, never with invented scores presented as real.
- Tests run against PostgreSQL in CI, not SQLite, because JSONB, ULIDs and
  locking behaviour must match production.
