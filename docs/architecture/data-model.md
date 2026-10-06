# Data Model

The CodeDNA domain model in PostgreSQL. **Status: Phase 07.** The tables,
models, relationships, constraints and factories exist. Developer profiles
(Phase 06), projects and source snapshots (Phase 07) have APIs. The analysis
pipeline (Phase 10) creates analysis runs and stores their verified results;
scoring comes in Phase 11.

Related: [ADR-003](../decisions/ADR-003-storage.md) (storage),
[ADR-004](../decisions/ADR-004-dna-scoring.md) (versioning, immutability),
[ADR-005](../decisions/ADR-005-service-communication.md) (analysis runs),
[data-flow.md](data-flow.md), [backend.md](backend.md).

## Entities

```text
users ──1:n──► projects ──1:n──► source_snapshots ──1:n──► analysis_runs ──1:0..1──► dna_snapshots
                                                               └──1:0..1──► analysis_results (Phase 10)
  │               │                                            ▲                        │  │
  │               └────────────────1:n─────────────────────────┘                        │  │
  │               └────────────────1:n──────────────────────────────────────────────────┘  │
  └───────────────────────────────1:n──────────────────────────────────────────────────────┘
```

| Table | What it is | Mutable? |
|---|---|---|
| `users` | Authentication identity: name, email, password (Phase 03) | yes |
| `developer_profiles` | The developer's product profile, exactly one per user (Phase 06) | yes (editable fields) |
| `projects` | A developer's project and its source origin | yes (descriptive fields, ACTIVE → ARCHIVED) |
| `source_snapshots` | Immutable reference to the exact source analyzed | **never** |
| `analysis_runs` | One pipeline execution against one snapshot, for one result type | only until a terminal state |
| `analysis_results` | The verified analyzer result of one successful run (Phase 10) | **never** |
| `dna_snapshots` | Immutable DNA result of one successful run | **never** |

There is deliberately no separate `repositories` or `analyses` table. A
project carries its source origin (`source_type`, `repository_url`). A
re-analysis is simply another `analysis_run` for the same snapshot. Provider
integrations (Phase 19) will add their own tables when they exist.

### Relationships (Eloquent)

| Model | Relationships |
|---|---|
| `User` | `developerProfile()` hasOne; `projects()` hasMany |
| `DeveloperProfile` | `user()` belongsTo |
| `Project` | `user()` belongsTo; `sourceSnapshots()`, `analysisRuns()`, `dnaSnapshots()` hasMany |
| `SourceSnapshot` | `project()` belongsTo; `analysisRuns()` hasMany |
| `AnalysisRun` | `project()`, `sourceSnapshot()` belongsTo; `dnaSnapshot()` hasOne |
| `DnaSnapshot` | `user()`, `project()`, `analysisRun()` belongsTo |

All relationships carry generic return types (`BelongsTo<Project, $this>`).
Outside production, strict mode makes lazy loading throw, so callers must
eager-load (`with('sourceSnapshots.analysisRuns')`).

## Tables

All primary keys are ULIDs (`HasUlids`, the same as `users`). All timestamps
are `timestamp(0)` columns holding UTC, as for `users`. The application
timezone is UTC, and the boot-time configuration validator enforces this.

### developer_profiles

Authentication identity stays in `users`. Everything a developer says about
themselves lives here, so product fields never pile up on the identity
table.

| Column | Type | Notes |
|---|---|---|
| `id` | ULID PK | |
| `user_id` | ULID FK → `users`, **unique**, `RESTRICT` | exactly one profile per user |
| `display_name` | varchar(100), null | not blank when set |
| `bio` | text, null | at most 1000 characters, not blank when set |
| `avatar_url` | varchar(2048), null | `https://` only (no uploads yet) |
| `timezone` | varchar(64), default `UTC` | canonical IANA identifier |
| `locale` | varchar(16), default `en` | `SupportedLocale`: `en`, `uz`, `ru` |
| `country_code` | char(2), null | two uppercase letters |
| `city`, `job_title`, `company` | varchar(100), null | not blank when set |
| `website_url`, `linkedin_url` | varchar(2048), null | `https://` only |
| `github_username` | varchar(39), null | GitHub's format; a self-declared handle, **not** a linked account |
| `preferred_language` | varchar(64), null | `ProgrammingLanguage` value, same format as `projects.language` |
| `metadata` | jsonb, null | internal only: never fillable, never returned by the API; object ≤ 16 KiB |
| `created_at`, `updated_at` | timestamp(0) | |

- **Creation:** `RegisterUser` creates the user and the profile (defaults
  only: `UTC`, `en`, everything else `NULL`) in one transaction. The
  migration gave every existing user a default profile. A user created
  any other way gets one on first API access (`ResolveDeveloperProfile`,
  `createOrFirst` on the unique `user_id`).
- **Validation split:** the application decides which values are allowed
  (IANA time zone list, supported locales, programming languages, URL
  rules). The database checks formats and lengths (`https://` prefix,
  locale and country code shape, GitHub username shape, blank strings,
  metadata type and size), so no write path can store malformed data.
  Neither list of allowed values is a CHECK constraint, so adding a locale
  or language needs no migration.
- **Time zone and locale are presentation preferences.** All stored
  timestamps remain UTC. The locale is stored only; the UI is not
  translated yet.
- **Never stored here:** passwords, tokens, OAuth or GitHub credentials,
  session data, source code. `metadata` is not a user-data bucket: the API
  cannot write it, and core fields are relational columns.
- **Deletion:** `RESTRICT`, like the history tables. A user with a profile
  cannot be deleted. Account deletion is a future explicit purge workflow.

### projects

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | |
| `user_id` | ulid FK → users | `RESTRICT` on delete |
| `name` | varchar(255) | not blank |
| `slug` | varchar(100) | `^[a-z0-9]+(-[a-z0-9]+)*$`, unique per user |
| `description` | text null | |
| `default_branch` | varchar(255) null | |
| `source_type` | varchar(32) | `UPLOAD` \| `REPOSITORY` |
| `repository_url` | varchar(2048) null | required for `REPOSITORY`, absent for `UPLOAD`; must be `https://` (no `file://`, `ssh`, etc.) |
| `language` | varchar(64) null | lowercase identifier (`php`, `typescript`, …) |
| `status` | varchar(32) | `ACTIVE` (default) \| `ARCHIVED` |
| `metadata` | jsonb null | object, ≤ 16 KiB |
| `created_at`, `updated_at` | timestamp | |

No soft deletes. Archiving (`status`) covers "hide but keep". There is no
delete endpoint: deletion that also removes stored source objects is a
deliberate purge workflow (a later phase, together with account deletion),
never an incidental `delete()`.

Through the API (Phase 07), a project's owner is always the authenticated
user, the status changes only through `POST /projects/{project}/archive`,
`source_type` is fixed at creation, and an archived project is read-only.
`metadata` is internal and never returned.

### source_snapshots

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | |
| `project_id` | ulid FK → projects | `RESTRICT` |
| `version` | integer | 1, 2, 3, … per project; unique `(project_id, version)` |
| `source_type` | varchar(32) | how this snapshot was obtained: `UPLOAD` \| `REPOSITORY` |
| `storage_disk` | varchar(64) | Laravel disk name of the S3-compatible store |
| `storage_key` | varchar(1024) | object key (no leading `/`, no `..`); unique `(storage_disk, storage_key)` |
| `source_hash` | char(64) | SHA-256 of the archive, lowercase hex: content identity |
| `size_bytes` | bigint | ≥ 0 |
| `file_count` | integer | ≥ 0 |
| `primary_language` | varchar(64) null | |
| `metadata` | jsonb null | object, ≤ 16 KiB. Uploads record `{"archive": {"format": "zip", "entries", "directories", "uncompressed_bytes"}}`; repository sources will add the commit SHA |
| `idempotency_key_hash` | char(64) null | SHA-256 of the upload's `Idempotency-Key` header (never the raw key); unique per project when set (Phase 07) |
| `created_at` | timestamp | **no `updated_at`** |

`source_hash` is *not* unique: the same content may be uploaded again,
within one project or by different users. `(project_id, source_hash)` is
indexed for "already uploaded?" checks within a project. Hashes are never
looked up across tenants.

For uploads (Phase 07, [data-flow.md](data-flow.md#source-upload)):

- `storage_key` is always `projects/{project_id}/snapshots/{snapshot_id}/source.zip`
  (optionally under `SOURCE_STORAGE_PREFIX`). It is built from server-generated
  ULIDs only; no client input or file name is ever part of a key. The
  snapshot ID is generated before the object is stored, so the key is unique
  to one upload attempt and cleanup after a failed insert can never touch
  another snapshot's object.
- `source_hash` and `size_bytes` are computed by the server over the stored
  bytes. `file_count` counts regular files (directories and macOS
  `__MACOSX/` metadata excluded).
- `primary_language` is a file-extension heuristic (the most common
  recognized source extension, ignoring `node_modules`, `vendor`, `.git`,
  `dist` and `build`), not analysis. It is `NULL` when nothing is
  recognized or two languages tie.
- The API never returns `storage_disk`, `storage_key`, `metadata` or
  `idempotency_key_hash`.

### analysis_runs

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | |
| `project_id` | ulid FK → projects | `RESTRICT` |
| `source_snapshot_id` | ulid | composite FK `(source_snapshot_id, project_id)` → `source_snapshots(id, project_id)`: the snapshot must belong to the same project |
| `result_type` | varchar(32) | `foundation` (default) \| `static_analysis` (Phase 10): the analyzer result type the run asks for; never changes |
| `status` | varchar(32) | `QUEUED` (default) \| `RUNNING` \| `SUCCEEDED` \| `FAILED` \| `CANCELLED` |
| `analyzer_version`, `ir_version`, `metrics_version`, `scoring_version`, `contract_version` | varchar(32) null | once `SUCCEEDED`: `analyzer`, `ir` and `contract` required, `metrics` required for `static_analysis`; `scoring` stays `NULL` until Phase 11 (relaxed in Phase 10, which made foundation results persistable) |
| `idempotency_key` | varchar(64) null | the analyzer's `Idempotency-Key` (set to the run ID in Phase 10); unique when present |
| `started_at` | timestamp null | set on `RUNNING` |
| `completed_at` | timestamp null | set exactly when the run reaches a terminal state |
| `failed_at` | timestamp null | set exactly when `FAILED` |
| `failure_code` | varchar(64) null | contract vocabulary (`SOURCE_TOO_LARGE`, …); required exactly when `FAILED` |
| `failure_message` | varchar(1000) null | user-safe text only, only when `FAILED` |
| `result_hash` | char(64) null | required exactly when `SUCCEEDED` |
| `metadata` | jsonb null | object, ≤ 16 KiB (e.g. attempts, request IDs) |
| `created_at`, `updated_at` | timestamp | |

A snapshot may have many runs (several result types, re-runs after
failures). At most one run per (snapshot, result type) can be `QUEUED` or
`RUNNING` (partial unique index, Phase 10); `StartAnalysis` also returns an
existing `SUCCEEDED` run instead of creating another
([idempotency policy](data-flow.md#idempotency-policy)). `metadata` holds the
start request's ID, the requesting user, the attempts (number, request ID,
start time, error code) and the current job lease.

### analysis_results

One row per `SUCCEEDED` run (Phase 10), written in the same transaction
that marks the run `SUCCEEDED` (`PersistAnalysisResult`).

| Column | Type | Notes |
|---|---|---|
| `analysis_run_id` | ulid PK, FK → analysis_runs | `RESTRICT`; one result per run |
| `result_type` | varchar(32) | `foundation` \| `static_analysis`; equals the run's |
| `result_hash` | char(64) | equals the run's; the SHA-256 of the result's canonical JSON |
| `contract_version`, `analyzer_version`, `ir_version` | varchar(32) | from the result |
| `metrics_version` | varchar(32) null | required for `static_analysis` |
| `result` | jsonb | the verified analyzer response body as received (object); re-verifiable against `result_hash`; no source text |
| `size_bytes` | bigint | size of the response body |
| `created_at` | timestamp | no `updated_at`: immutable |

The analyzer's output evolves with its IR and metrics versions, so the
result is kept as one JSONB document rather than normalized into tables.

### dna_snapshots

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | |
| `user_id` | ulid FK → users | |
| `project_id` | ulid | composite FK `(project_id, user_id)` → `projects(id, user_id)`: the user must own the project |
| `analysis_run_id` | ulid, unique | composite FK `(analysis_run_id, project_id)` → `analysis_runs(id, project_id)`; at most one DNA per run |
| version columns | varchar(32) | `scoring_version` required; the others nullable |
| `status` | varchar(32) | `READY` \| `INSUFFICIENT_DATA` |
| `overall_score` | numeric(5,4) null | 0–1, 4 decimals (ADR-004); present exactly when `READY` |
| `dimensions` | jsonb | object (per-dimension status, score and evidence), ≤ 64 KiB |
| `competencies` | jsonb null | object, ≤ 64 KiB |
| `strengths`, `weaknesses` | jsonb null | arrays, ≤ 64 KiB |
| `evidence` | jsonb null | object, ≤ 64 KiB |
| `result_hash` | char(64) | hash of the analyzer result this DNA came from |
| `created_at` | timestamp | **no `updated_at`** |

`user_id` and `project_id` are denormalized on purpose. They are the main
query dimensions ("my DNA history", "this project's DNA history"), and
composite foreign keys guarantee they agree with the run.

## States

States are **VARCHAR columns with CHECK constraints**, mirrored by PHP backed
enums (`app/Enums`) through Eloquent enum casts:

| Enum | Values |
|---|---|
| `ProjectStatus` | `ACTIVE`, `ARCHIVED` |
| `SourceType` | `UPLOAD`, `REPOSITORY` (projects and snapshots) |
| `AnalysisRunStatus` | `QUEUED`, `RUNNING`, `SUCCEEDED`, `FAILED`, `CANCELLED` |
| `DnaSnapshotStatus` | `READY`, `INSUFFICIENT_DATA` |

**Why not PostgreSQL `ENUM` types?** Adding or renaming a value then needs
`ALTER TYPE`, which has transaction restrictions and couples deployments to
type migrations. A `CHECK` constraint is equally strict and changes with an
ordinary migration. Invalid values are refused twice: by the enum cast in
PHP (`ValueError`) and by the database (constraint violation). Migrations
spell the allowed values out instead of reading the PHP enums, so an old
migration keeps meaning what it meant. A test asserts that the enums and
the constraints agree.

## Lifecycle

```text
Project:          ACTIVE ──archive()──► ARCHIVED   (irreversible; read-only, no new snapshots)

Source snapshot:  StoreUploadedSource: inspect → hash → store object → RecordSourceSnapshot ──► immutable

Analysis run:     QUEUED ──markRunning()──► RUNNING ──┬─ markSucceeded() ──► SUCCEEDED
                     │                                ├─ markFailed()    ──► FAILED
                     │                                └─ cancel()        ──► CANCELLED
                     ├─ markFailed() ──► FAILED      (dispatch failure, stale run)
                     └─ cancel()     ──► CANCELLED

DNA snapshot:     created (READY or INSUFFICIENT_DATA) from a SUCCEEDED run ──► immutable
```

- Allowed transitions live in `AnalysisRunStatus::canTransitionTo()`. The
  model checks *every* Eloquent update against them, so even a hand-written
  `->save()` cannot skip a state.
- Each transition method also sets its timestamps and fields
  (`started_at`, `completed_at`, `failed_at`, `failure_code`, versions,
  `result_hash`). CHECK constraints keep status and fields consistent; for
  example, a `SUCCEEDED` row without a `result_hash` or versions is
  impossible.
- Retries of transient errors (Phase 10) happen inside one run, which stays
  `RUNNING`. A user-initiated re-analysis creates a new run.
- `RecordSourceSnapshot` (`app/Actions/Snapshots`) assigns the next version
  inside a transaction that locks the project row, so concurrent uploads
  get distinct, gap-free versions. `ConcurrentUploadTest` proves this with
  six forked processes uploading to one project at the same moment.
- `ARCHIVED → ACTIVE` is not offered: archiving is irreversible for now.

## Immutability

Historical records (`source_snapshots`, terminal `analysis_runs`,
`analysis_results`, `dna_snapshots`) are append-only. This is an architectural invariant.

| Layer | Mechanism |
|---|---|
| Application | Eloquent `updating`/`deleting` hooks throw `DomainRuleViolation` (snapshots and analysis results: always; runs: updates once terminal, deletes always). An analysis result can only be created for a `SUCCEEDED` run with the same `result_hash` and result type |
| Mass assignment | Snapshots and runs have no fillable attributes. They are written only by domain operations (`RecordSourceSnapshot`, run transition methods) and factories. Strict mode makes a guarded attribute throw instead of being silently dropped |
| Schema | No `updated_at` on snapshot tables; `RESTRICT` foreign keys, so deleting a user, project, snapshot or run with history fails instead of cascading |
| API | No update or delete endpoints exist for these records (none planned) |
| Creation rule | A DNA snapshot can only be created for a `SUCCEEDED` run (model hook), at most one per run (unique) |

**Known gap (deliberate):** query-builder or raw SQL updates bypass Eloquent
hooks. Database triggers that reject `UPDATE` on the snapshot tables would
close this, at the cost of making the future purge workflow (account
deletion) more involved. This is deferred, and listed as a decision for
review.

## Storage boundary

PostgreSQL stores **references, never source code** (ADR-003):
`storage_disk`, `storage_key`, `source_hash`, `size_bytes`, `file_count` and
descriptive metadata. Archive entry names are not stored either (they may
be sensitive); only counts and sizes are. Archives live in S3-compatible storage (MinIO locally,
Cloudflare R2 in production).

Guardrails against source code leaking into the database:

- no binary (`bytea`) or content-like columns (a test asserts this)
- every free-form JSONB column is size-capped (16 KiB for metadata, 64 KiB
  for DNA fields), so files cannot be stored as "metadata"
- `failure_message` is limited to 1000 characters of user-safe text
- no credentials, OAuth or installation tokens exist in the domain model

## JSONB

JSONB holds versioned, evolving structure that is read with its parent row:
`metadata`, `dimensions`, `competencies`, `strengths`, `weaknesses`,
`evidence`. Anything used to filter, join or order (owner, project,
status, versions, hashes, timestamps) is a relational column.

- Object columns use the `App\Casts\JsonObject` cast: an empty PHP array is
  stored as `{}`, and lists are rejected. CHECK constraints enforce the JSON
  type (`object`/`array`) and the size caps.
- **JSONB normalizes key order.** Never recompute a hash (e.g.
  `result_hash`) from JSON read back from the database. Hashes come from the
  analyzer's canonical JSON (ADR-004).
- JSON paths are queryable when needed (`where('metadata->import->format', …)`).
  No JSONB (GIN) indexes exist until a real query needs one.

## Indexes

Every index serves a known or planned query:

| Index | Query |
|---|---|
| `projects (user_id, slug)` unique | find a user's project by slug; also serves "projects of a user" |
| `projects (user_id, status)` | a user's active / archived projects |
| `projects (id, user_id)` unique | target of `dna_snapshots`' composite FK |
| `source_snapshots (project_id, version)` unique | snapshot history of a project; latest version |
| `source_snapshots (project_id, source_hash)` | "has this project already uploaded this content?" |
| `source_snapshots (storage_disk, storage_key)` unique | one stored object belongs to one snapshot |
| `source_snapshots (id, project_id)` unique | target of `analysis_runs`' composite FK |
| `source_snapshots (project_id, idempotency_key_hash)` unique partial, non-null only | idempotent upload retries (Phase 07) |
| `analysis_runs (project_id, created_at)` | run history of a project |
| `analysis_runs (source_snapshot_id)` | runs of a snapshot |
| `analysis_runs (status, updated_at)` partial, `QUEUED`/`RUNNING` only | stale-run sweeper and in-progress lookups (stays small) |
| `analysis_runs (idempotency_key)` unique partial, non-null only | analyzer request identity |
| `analysis_runs (id, project_id)` unique | target of `dna_snapshots`' composite FK |
| `analysis_runs (source_snapshot_id, result_type)` unique partial, `QUEUED`/`RUNNING` only | at most one active run per logical analysis (Phase 10) |
| `analysis_results (analysis_run_id)` primary key | the result of a run |
| `dna_snapshots (analysis_run_id)` unique | one DNA per run; DNA of a run |
| `dna_snapshots (user_id, created_at)` | a developer's DNA history |
| `dna_snapshots (project_id, created_at)` | a project's DNA history |

PostgreSQL does not index foreign keys automatically. Every FK column above
is the leading column of some index.

## Versioning

Successful runs and DNA snapshots record `analyzer_version`, `ir_version`,
`metrics_version`, `scoring_version` and `contract_version` (ADR-004 §2,
`App\Support\AnalysisVersions`). Snapshots are compared only within equal
`metrics_version` and `scoring_version`. Old snapshots are never rewritten
when the engine changes: re-analysis creates new runs and new DNA snapshots.

## Development and test data

- `DatabaseSeeder` is intentionally empty: there are no demo users or
  projects.
- Factories (`database/factories`) build valid graphs for tests:
  `DnaSnapshot::factory()` creates user → project → snapshot → succeeded
  run → DNA. `User::factory()` creates no profile by itself; use
  `User::factory()->has(DeveloperProfile::factory())` (states `minimal()`
  and `complete()`).
- DNA factory values are **test fixtures**, marked
  `evidence.fixture = true`, and are never real CodeDNA results.
- Tests run against `codedna_test` only (Phase 03 guard).
