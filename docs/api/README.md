# CodeDNA Public API (v1)

The public product API is served by Laravel under `/api/v1`. The **internal**
analyzer API is separate and never public; see
[internal-analyzer-contract.md](internal-analyzer-contract.md).

**Status:** Phase 07. The endpoints below are implemented and tested, and
the Next.js frontend consumes them. Planned endpoints are listed at the end
and **do not exist yet**. The frontend's TypeScript mirror of this contract
is `frontend/src/lib/api/types.ts`; keep it in sync with the backend.

## Versioning

- All application endpoints are under **`/api/v1/`** (`routes/api_v1.php`).
- Within `v1`, changes are additive only: new endpoints, new optional request
  fields, new response fields. Clients must ignore unknown response fields.
- A breaking change gets a new prefix (`/api/v2/`, `routes/api_v2.php`), and
  `v1` keeps working through a documented deprecation window, announced with
  the `Deprecation` and `Sunset` response headers.
- Infrastructure endpoints outside the versioned API:
  - `GET /up`: Laravel liveness, used by the container healthcheck.
  - `GET /sanctum/csrf-cookie`: Sanctum's CSRF cookie endpoint.

## Endpoints

| Method | Path | Auth | Success | Purpose |
|---|---|---|---|---|
| `GET` | `/api/v1/health` | none | 200 / 503 | Readiness of the API and its dependencies |
| `GET` | `/sanctum/csrf-cookie` | none | 204 | Issue the `XSRF-TOKEN` cookie |
| `POST` | `/api/v1/auth/register` | browser session | 201 | Create an account and log in |
| `POST` | `/api/v1/auth/login` | browser session | 200 | Log in |
| `POST` | `/api/v1/auth/logout` | authenticated | 204 | Log out |
| `PATCH` | `/api/v1/auth/password` | authenticated, browser session | 204 | Change the password (ends the session) |
| `GET` | `/api/v1/me` | authenticated | 200 | Current user |
| `GET` | `/api/v1/profile` | authenticated | 200 | The caller's developer profile |
| `PATCH` | `/api/v1/profile` | authenticated | 200 | Update the caller's developer profile |
| `GET` | `/api/v1/projects` | authenticated | 200 | The caller's projects (paginated) |
| `POST` | `/api/v1/projects` | authenticated | 201 | Create a project |
| `GET` | `/api/v1/projects/{project}` | owner | 200 | One project |
| `PATCH` | `/api/v1/projects/{project}` | owner | 200 | Update descriptive fields |
| `POST` | `/api/v1/projects/{project}/archive` | owner | 200 | Archive (irreversible, idempotent) |
| `GET` | `/api/v1/projects/{project}/source-snapshots` | owner | 200 | Snapshot history (paginated, newest first) |
| `POST` | `/api/v1/projects/{project}/source-snapshots` | owner | 201 / 200 | Upload a ZIP archive as a new snapshot (200 = idempotent replay) |
| `GET` | `/api/v1/projects/{project}/source-snapshots/{snapshot}` | owner | 200 | One snapshot |
| `GET` | `/api/v1/projects/{project}/analyses` | owner | 200 | Analysis runs (paginated, newest first) |
| `POST` | `/api/v1/projects/{project}/analyses` | owner | 202 / 200 | Start an analysis of a snapshot (200 = the existing equivalent run) |
| `GET` | `/api/v1/projects/{project}/analyses/{run}` | owner | 200 | One analysis run: status, failure, result metadata |
| `GET` | `/api/v1/projects/{project}/analyses/{run}/result` | owner | 200 | The verified analyzer result of a `SUCCEEDED` run |
| `GET` | `/api/v1/projects/{project}/dna` | owner | 200 | DNA snapshots (paginated, newest first) |
| `GET` | `/api/v1/projects/{project}/dna/{snapshot}` | owner | 200 | One DNA snapshot with dimensions and evidence |
| `GET` | `/api/v1/projects/{project}/competencies` | owner | 200 | Competency snapshots (paginated, newest first) |
| `GET` | `/api/v1/projects/{project}/competencies/{snapshot}` | owner | 200 | One competency matrix with levels, evidence and provenance |
| `GET` | `/api/v1/projects/{project}/skill-gaps` | owner | 200 | Skill gap snapshots (paginated, newest first) |
| `GET` | `/api/v1/projects/{project}/skill-gaps/{snapshot}` | owner | 200 | One skill gap analysis: targets, gaps, priorities, provenance |
| `GET` | `/api/v1/projects/{project}/assessments` | owner | 200 | AI assessments (paginated, newest first) |
| `POST` | `/api/v1/projects/{project}/assessments` | owner | 202 / 200 | Request an AI interpretation of a skill gap analysis (200 = the existing one) |
| `GET` | `/api/v1/projects/{project}/assessments/{assessment}` | owner | 200 | One AI assessment: status, output, evidence, versions, lineage |

There is no `DELETE` for projects (`405`) and no update, delete or download
for snapshots. DNA, competency and skill gap snapshots are read-only (no
write method on `/dna`, `/competencies` or `/skill-gaps`). See
[Projects](#projects), [Source snapshots](#source-snapshots),
[Analyses](#analyses), [DNA](#dna), [Competencies](#competencies),
[Skill gaps](#skill-gaps) and [AI assessments](#ai-assessments).

### `GET /api/v1/health`

Checks PostgreSQL (`select 1`) and Redis (`PING`). The response is `200`
when every check passes and `503` otherwise. **Both** cases use the same
body, so monitors can read the HTTP status and people can read the checks.

```json
{
  "data": {
    "status": "ok",
    "service": "codedna-api",
    "version": "dev",
    "api_version": "v1",
    "checks": { "database": "ok", "redis": "ok" }
  }
}
```

Failed checks say only `"fail"`. Connection errors are logged, never
returned, because they can contain hosts or credentials.

### `POST /api/v1/auth/register`

```json
{ "name": "Ada Lovelace", "email": "ada@example.com", "password": "…", "password_confirmation": "…" }
```

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `email` | required, RFC email, max 255, unique. Trimmed and lowercased, so uniqueness is case-insensitive |
| `password` | required, 8–72 characters (bcrypt uses at most 72 bytes), must match `password_confirmation` |

On success the response is `201` with the user resource, and the session is
logged in (the session ID is regenerated). Duplicate email gives `422
VALIDATION_FAILED` with `details.fields.email`.

### `POST /api/v1/auth/login`

```json
{ "email": "ada@example.com", "password": "…" }
```

On success the response is `200` with the user resource, and the session ID
is regenerated. Wrong password and unknown email return the **same** `422
INVALID_CREDENTIALS` error. Credential checks run in a fixed-duration
timebox, so timing doesn't reveal whether an account exists. (Registration
does reveal that an email is taken, which is inherent to sign-up.)

`422` was chosen over `401` so that `401` always means "you have no
session", which a client handles by sending the user to the login page.

### `POST /api/v1/auth/logout`

Returns `204` with no body. It logs out, invalidates the session (data and
ID), and rotates the CSRF token. Without a session it returns `401`.

### `GET /api/v1/me`

```json
{
  "data": {
    "id": "01k6m2y5a7j1x9v3q8n4r2t6wz",
    "type": "user",
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "email_verified_at": null,
    "created_at": "2026-10-05T12:00:00Z"
  }
}
```

Users are serialized by `UserResource` with an explicit field list.
`password`, `remember_token` and any future attribute are never exposed
unless they are added to the resource. `/me` is the identity only; the
developer profile has its own endpoint.

### `GET /api/v1/profile`

Returns the authenticated developer's profile. There is no profile ID in the
URL: a caller can only ever read and change their own profile, so another
user's profile cannot be addressed.

```json
{
  "data": {
    "id": "01k6m2y5a7j1x9v3q8n4r2t6xa",
    "type": "developer_profile",
    "display_name": "Ada",
    "bio": "Backend developer.",
    "avatar_url": null,
    "timezone": "Asia/Tashkent",
    "locale": "uz",
    "country_code": "UZ",
    "city": "Tashkent",
    "job_title": "Senior Engineer",
    "company": null,
    "website_url": "https://ada.example.com",
    "github_username": "ada-lovelace",
    "linkedin_url": null,
    "preferred_language": "php",
    "updated_at": "2026-10-06T12:00:00Z"
  }
}
```

Profiles are serialized by `DeveloperProfileResource` with an explicit field
list. The owner ID and the internal `metadata` column are never returned.
Every user has a profile: registration creates it. A user created some other
way gets the default profile on first access (still `200`).

### `PATCH /api/v1/profile`

A partial update: send only the fields to change. `null` (or `""`) clears an
optional field. Any other field (`id`, `user_id`, `metadata`, ...) is
ignored. The response is the updated profile (`200`).

| Field | Rules |
|---|---|
| `display_name`, `city`, `job_title`, `company` | optional, string, max 100 |
| `bio` | optional, string, max 1000 |
| `avatar_url`, `website_url`, `linkedin_url` | optional, absolute `https://` URL with a host, max 2048, no `user:password@`, no whitespace |
| `timezone` | cannot be cleared; a canonical IANA time zone (`UTC`, `Asia/Tashkent`, `Europe/Moscow`). Offsets such as `UTC+5`, legacy aliases and wrong case are rejected |
| `locale` | cannot be cleared; one of `en`, `uz`, `ru` |
| `country_code` | optional, two letters; stored uppercase (`uz` → `UZ`) |
| `github_username` | optional, GitHub's format: 1–39 letters, digits and single hyphens, not at either end. A leading `@` is removed |
| `preferred_language` | optional, one of `c`, `cpp`, `csharp`, `dart`, `elixir`, `go`, `java`, `javascript`, `kotlin`, `php`, `python`, `ruby`, `rust`, `scala`, `swift`, `typescript` |

`timezone` and `locale` are presentation preferences. API timestamps stay
UTC, and the UI is not translated yet.

### `PATCH /api/v1/auth/password`

```json
{ "current_password": "…", "password": "…", "password_confirmation": "…" }
```

| Field | Rules |
|---|---|
| `current_password` | required, must match the account's password |
| `password` | required, the registration policy (8–72 characters), must match `password_confirmation`, must differ from `current_password` |

On success the response is `204` with no body. The password is re-hashed
with bcrypt, the remember-me token is rotated, and **the current session is
invalidated** (as on logout), so the client must sign in again. Every other
session of the user is rejected on its next request (`401`), because
Sanctum's `AuthenticateSession` middleware compares the password hash each
session was created with. A wrong current password returns `422
VALIDATION_FAILED` with `details.fields.current_password`. Password values
never appear in responses or logs; a log line records only that the user's
password changed.

### Account deletion and email verification

There is no account deletion endpoint. Users, profiles and project history
use `RESTRICT` foreign keys, so deleting an account needs a deliberate purge
workflow, planned for a later phase. Email verification is not enforced yet
(`email_verified_at` is returned but nothing requires it); see
[backend.md](../architecture/backend.md#authentication-and-authorization).

## Projects

A project belongs to exactly one user. **Every project route is
owner-only:** another user's project answers `404 RESOURCE_NOT_FOUND`,
exactly like a project that does not exist, so IDs reveal nothing.
Authorization runs before validation. Project IDs in URLs must be ULIDs.

```json
{
  "data": {
    "id": "01k6p0a1b2c3d4e5f6g7h8j9km",
    "type": "project",
    "name": "Billing Service",
    "slug": "billing-service",
    "description": null,
    "default_branch": "main",
    "source_type": "UPLOAD",
    "repository_url": null,
    "language": "php",
    "status": "ACTIVE",
    "created_at": "2026-10-07T09:30:00Z",
    "updated_at": "2026-10-07T09:30:00Z"
  }
}
```

The owner ID and internal `metadata` are never returned.

### `GET /api/v1/projects`

The caller's projects, newest first (`created_at` then `id`, both
descending, so the order is stable). Query: `page`, `per_page` (default 25,
max 100), optional `status=ACTIVE|ARCHIVED`.

### `POST /api/v1/projects`

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `slug` | required, `^[a-z0-9]+(-[a-z0-9]+)*$`, max 100, unique among the caller's projects (other users may use the same slug). Uppercase input is lowercased |
| `description` | optional, string, max 2000 |
| `default_branch` | optional, a git branch name such as `main` or `release/2026.10` (no `..`, `//`, `@{`, trailing `/`, `.` or `.lock`), max 255 |
| `source_type` | required, `UPLOAD` or `REPOSITORY` |
| `repository_url` | required for `REPOSITORY`, forbidden for `UPLOAD`; an `https://` URL with a host and no credentials, max 2048 |
| `language` | optional, one of the [programming languages](#patch-apiv1profile) |

New projects are `ACTIVE`. Fields not listed (`id`, `user_id`, `status`,
`metadata`, ...) are ignored. `REPOSITORY` projects only record the URL:
cloning and provider integrations come in a later phase, and they cannot
receive uploads.

### `PATCH /api/v1/projects/{project}`

Partial update of `name`, `slug`, `description`, `default_branch`,
`language`, and `repository_url` (repository projects only; required, not
clearable). Sending `status` or `source_type` is a `422` with an
explanation; other unknown fields are ignored. Archived projects are
read-only: `409 PROJECT_ARCHIVED`.

### `POST /api/v1/projects/{project}/archive`

`ACTIVE → ARCHIVED`, returning the project. Archiving an archived project
returns it unchanged (`200`). It is irreversible for now. An archived project
keeps its full history but cannot be edited and accepts no new source.
**There is no delete:** history is protected by `RESTRICT` foreign keys, and
removing a project, its snapshots and their stored objects needs a deliberate
purge workflow (a later phase).

## Source snapshots

A source snapshot is an immutable, versioned record of one uploaded archive.
Uploading **stores** source; it does not analyze it. Analyses are started
separately ([Analyses](#analyses)).

```json
{
  "data": {
    "id": "01k6p0b7x2y3z4a5b6c7d8e9fg",
    "type": "source_snapshot",
    "project_id": "01k6p0a1b2c3d4e5f6g7h8j9km",
    "version": 1,
    "source_type": "UPLOAD",
    "source_hash": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
    "size_bytes": 48213,
    "file_count": 37,
    "primary_language": "php",
    "created_at": "2026-10-07T10:00:00Z"
  }
}
```

- `version` counts 1, 2, 3, … per project, assigned under a lock, so
  concurrent uploads never share or skip a version.
- `source_hash` (SHA-256) and `size_bytes` are computed by the server over
  the archive bytes; client-supplied values are ignored.
- `file_count` counts regular files; directories and macOS `__MACOSX/`
  metadata are excluded.
- `primary_language` is the most common recognized source file extension
  (ignoring `node_modules`, `vendor`, `.git`, `dist`, `build`), or `null`
  when nothing is recognized or there is a tie. It is a file-name
  heuristic, not analysis.
- Storage details (disk, object key, bucket, URLs), archive entry names and
  contents are **never** returned. There is no download endpoint.

### `POST /api/v1/projects/{project}/source-snapshots`

`multipart/form-data` with one field, `archive`: a ZIP file. Requires a
browser session and the CSRF token like every other mutation.

| Check (in order) | Failure |
|---|---|
| Owner | `404 RESOURCE_NOT_FOUND` |
| `archive` present and uploaded | `422 VALIDATION_FAILED` (`details.fields.archive`) |
| Project `ACTIVE` | `409 PROJECT_ARCHIVED` |
| Project `source_type` is `UPLOAD` | `409 INVALID_SOURCE_TYPE` |
| A valid, safe ZIP within the [upload limits](#upload-limits) | see [error codes](#error-codes) (`SOURCE_*`) |
| Storage reachable | `503 SERVICE_UNAVAILABLE` (nothing is created) |

Success is `201` with the snapshot. The archive is judged by its content:
the file name and MIME type are ignored, and nothing inside it is ever
extracted or executed (see
[data-flow.md](../architecture/data-flow.md#archive-inspection) for the full
list of rejected archives).

#### Idempotency

Send an `Idempotency-Key` header (8–128 characters from `A–Z a–z 0–9 . _ :
-`; a UUID is ideal) to make retries safe. Keys are scoped to the project.

| Request | Response |
|---|---|
| New key | `201`, a new snapshot |
| Same key, same archive bytes (e.g. a retry after a network failure) | `200` with the **original** snapshot and `Idempotent-Replayed: true` |
| Same key, different bytes | `422 IDEMPOTENCY_KEY_REUSED` |
| Malformed key | `400 BAD_REQUEST` |
| No key | `201`, a new snapshot every time (re-uploading identical source is allowed) |

#### Upload limits

| Limit | Variable | Default |
|---|---|---|
| Archive size | `SOURCE_MAX_ARCHIVE_BYTES` | 52,428,800 (50 MiB) |
| Total uncompressed size | `SOURCE_MAX_UNCOMPRESSED_BYTES` | 209,715,200 (200 MiB) |
| Files (directories and `__MACOSX/` not counted; all entries are limited to twice this) | `SOURCE_MAX_FILES` | 20,000 |
| One file, uncompressed | `SOURCE_MAX_SINGLE_FILE_BYTES` | 26,214,400 (25 MiB) |
| Path length (bytes) | `SOURCE_MAX_PATH_LENGTH` | 512 |

The archive, total-size and file-count defaults match the analyzer's limits
([internal contract](internal-analyzer-contract.md#7-timeouts-retries-and-limits)),
so an accepted upload is never too big to analyze. Files above the
analyzer's 1 MiB per-file limit are accepted here and skipped during
analysis. The archive limit must stay below PHP's `upload_max_filesize`
(52M) and Nginx's `client_max_body_size` (55m). A body above the Nginx limit is
answered by Nginx itself as `413 PAYLOAD_TOO_LARGE` in the standard error
envelope (with `request_id: null`, because Laravel never saw the request).
Uploads are also [rate limited](#rate-limiting).

### `GET /api/v1/projects/{project}/source-snapshots`

Newest version first; `page` and `per_page` as for projects.

### `GET /api/v1/projects/{project}/source-snapshots/{snapshot}`

One snapshot. A snapshot of another project, even one the caller owns,
answers `404`: the URL must name the snapshot's own project.

## Analyses

An analysis run is one execution of the analysis pipeline (Phase 10) against
one source snapshot of the project, for one **result type**: `foundation`
(the default; the file inventory) or `static_analysis` (parsing, IR 1.1,
static metrics and structural findings). Runs are processed asynchronously
by a queue worker that calls the internal analyzer; clients poll the run.
Neither result type is a score, and no DNA snapshot is created. The
pipeline is described in [data-flow.md](../architecture/data-flow.md#analysis-pipeline-phase-10).

```json
{
  "data": {
    "id": "01jbx7t3k4q9z8v6m2n5p1r0sa",
    "type": "analysis_run",
    "project_id": "01jbx7r2…",
    "source_snapshot_id": "01jbx7s9…",
    "result_type": "static_analysis",
    "status": "SUCCEEDED",
    "created_at": "2026-10-08T12:00:00Z",
    "started_at": "2026-10-08T12:00:01Z",
    "completed_at": "2026-10-08T12:00:04Z",
    "failure": null,
    "result": {
      "result_hash": "<64 hex>",
      "versions": { "contract": "1.0", "analyzer": "0.2.0", "ir": "1.1", "metrics": "1.0" }
    }
  }
}
```

| Field | Notes |
|---|---|
| `status` | `QUEUED` → `RUNNING` → `SUCCEEDED` \| `FAILED` (or `CANCELLED`). Terminal states never change. There is no progress percentage |
| `result_type` | `foundation` \| `static_analysis`; fixed when the run is created |
| `failure` | `null`, or for `FAILED` runs `{ "code", "message" }`: a stable code ([failure model](../architecture/data-flow.md#failure-model)) and a fixed, user-safe message |
| `result` | `null` until `SUCCEEDED`; then the result's hash and versions (`metrics` is `null` for foundation results) |

Runs never expose analyzer URLs, request signatures, pre-signed source
URLs, storage keys, attempt metadata or raw analyzer errors.

### `POST /api/v1/projects/{project}/analyses`

```json
{ "source_snapshot_id": "01jbx7s9…", "result_type": "static_analysis" }
```

- `source_snapshot_id` (required): a snapshot of **this** project. Any other
  value, including another user's snapshot, is `422 VALIDATION_FAILED` on
  `source_snapshot_id`. Clients cannot supply URLs, storage keys or analyzer
  options; unknown fields are ignored.
- `result_type` (optional, default `foundation`): `foundation` or
  `static_analysis`; anything else is `422 VALIDATION_FAILED`.
- **Idempotent per logical analysis** (project, snapshot, result type):
  - if an equivalent run is `QUEUED`, `RUNNING` or `SUCCEEDED`, it is
    returned with `200` and `Idempotent-Replayed: true` (no new run, no new
    work; a successful result is never recomputed);
  - otherwise (none yet, or only `FAILED`/`CANCELLED` runs) a new `QUEUED`
    run is created and returned with `202`. This is how a failed analysis is
    retried; the failed run stays in the history.
  - Concurrent identical requests resolve to the same run.
- `404 RESOURCE_NOT_FOUND` for another user's project; `409 PROJECT_ARCHIVED`
  for an archived project.

### `GET /api/v1/projects/{project}/analyses`

The project's runs, newest first, paginated like other collections
(`?page`, `?per_page`).

### `GET /api/v1/projects/{project}/analyses/{run}`

One run. A run of another project answers `404`. Poll this endpoint until
the status is terminal.

### `GET /api/v1/projects/{project}/analyses/{run}/result`

The verified analyzer result of a `SUCCEEDED` run, as stored:

```json
{ "data": { "analysis_run_id": "…", "type": "analysis_result", "result_type": "static_analysis",
            "result_hash": "<64 hex>", "result": { "result_type": "static_analysis", "ir": { … }, "metrics": { … }, "findings": { … } } } }
```

`result` is the analyzer's result document ([internal contract](internal-analyzer-contract.md#4-post-internalv1analyze)):
structure, counts, metrics and findings, never source text. A run that has
not succeeded answers `409 ANALYSIS_NOT_COMPLETED`.

## DNA

Read-only access to the immutable DNA snapshots that the scoring engine
creates from successful static analyses (Phase 11,
[dna-scoring-v1.md](../architecture/dna-scoring-v1.md)). Snapshots are never
created, changed or deleted through the API: every other method on these
paths answers `405`. Owner-only like every project resource: another user's
project, a missing project, a missing snapshot and a snapshot of another
project all answer `404 RESOURCE_NOT_FOUND`. Archived projects stay
readable.

> The CodeDNA Dashboard visualizes deterministic results produced by the CodeDNA scoring engine. It does not infer developer seniority, intelligence, personality, or professional level.

**Numbers.** Scores, weights, contributions and data quality are the stored
**decimal strings with exactly 4 places on a 0–1 scale** (`"0.8050"`), the
engine's own representation (ADR-004: no floating point). They are passed
through unchanged, never rounded or rescaled; `"0.8123"` stays `"0.8123"`.
Clients may display them on a 0–100 scale (`81.23`) but must not compute
them. Raw evidence counts are JSON integers.

**Missing is never zero.** `null` means "no value": `overall_score` is
`null` unless `status` is `READY`; a dimension's `score`, `effective_weight`
and `contribution` are `null` when it is `UNAVAILABLE`; a component's
`value` and `score` are `null` unless it is `AVAILABLE`; an evidence count is
`null` when the analysis did not provide it.

| Field | Values |
|---|---|
| `status` | `READY` (an overall score exists) · `INSUFFICIENT_DATA` (too little evidence for one; **not** a score of 0) |
| dimension `status` | `SCORED` · `UNAVAILABLE` (excluded; its weight is redistributed over the scored dimensions) |
| dimension `unavailable_reason`, component `status` | `AVAILABLE` · `INSUFFICIENT_EVIDENCE` (below the minimum, e.g. fewer than 5 functions) · `UNSUPPORTED` (the analyzer cannot measure it for these languages) · `MISSING` (absent from the result) |
| `data_quality` | 0–1: how much measurable input there was (parse coverage, evidence volume, measurement availability). **Not** a confidence or probability |

### `GET /api/v1/projects/{project}/dna`

Newest first (`created_at`, then `id`, descending), paginated like other
collections (`?page`, `?per_page` ≤ 100). Summaries only; the list does not
read dimensions or evidence.

```json
{ "data": [ { "id": "…", "type": "dna_snapshot", "project_id": "…", "analysis_run_id": "…",
              "source_snapshot_id": "…", "source_snapshot_version": 3, "status": "READY",
              "overall_score": "0.8050", "data_quality": "0.9000", "scoring_version": "1.0.0",
              "metrics_version": "1.0", "created_at": "2026-10-12T08:00:00Z" } ],
  "meta": { "current_page": 1, "per_page": 25, "total": 1, "last_page": 1 } }
```

There is no `updated_at`: snapshots never change.

### `GET /api/v1/projects/{project}/dna/{snapshot}`

The complete snapshot, read from storage (nothing is recomputed):

```json
{ "data": {
  "id": "…", "type": "dna_snapshot", "project_id": "…", "analysis_run_id": "…", "source_snapshot_id": "…",
  "status": "READY", "overall_score": "0.8050", "data_quality": "0.9000",
  "scoring_version": "1.0.0", "specification_fingerprint": "<64 hex>",
  "versions": { "scoring": "1.0.0", "metrics": "1.0", "analyzer": "0.2.0", "ir": "1.1", "contract": "1.0" },
  "result_hash": "<64 hex>", "created_at": "…",
  "source_snapshot": { "id": "…", "version": 3, "file_count": 12, "primary_language": "php", "created_at": "…" },
  "analysis_run": { "id": "…", "result_type": "static_analysis", "status": "SUCCEEDED", "completed_at": "…" },
  "dimensions": [
    { "dimension": "COMPLEXITY", "name": "Complexity", "description": "…", "status": "SCORED", "unavailable_reason": null,
      "score": "0.8125", "weight": "0.4000", "effective_weight": "0.4000", "contribution": "0.3250", "data_quality": "0.9000",
      "components": [
        { "key": "mean_cyclomatic_complexity", "description": "…", "share": false, "status": "AVAILABLE", "required": true,
          "weight": "0.5000", "value": "4.0000", "score": "0.7500", "best": "2.0000", "worst": "10.0000", "minimum_denominator": 5,
          "numerator": [ { "metric": "metrics.overall.complexity_total", "value": 160 } ],
          "denominator": [ { "metric": "metrics.overall.functions_total", "value": 40 } ] } ] } ],
  "aggregation": { "method": "weighted_mean_of_scored_dimensions", "minimum_scored_dimensions": 2,
                   "scored_dimensions": ["COMPLEXITY", "STRUCTURE", "CODE_HYGIENE"], "unavailable_dimensions": [],
                   "scored_weight": "1.0000", "renormalized": false },
  "availability": { "AVAILABLE": 7, "INSUFFICIENT_EVIDENCE": 0, "UNSUPPORTED": 0, "MISSING": 0 },
  "data_quality_breakdown": {
    "parse_coverage": { "value": "0.9000", "weight": "0.5000", "files_parsed": 9, "files_analyzable": 10 },
    "evidence_volume": { "value": "0.8000", "weight": "0.2500", "functions": 40, "target": 50 },
    "metric_availability": { "value": "1.0000", "weight": "0.2500", "available_components": 7, "components": 7 } } } }
```

- `dimensions` and `components` are lists in the scoring specification's
  order (storage does not keep key order). `description` and `share` come
  from the snapshot's own scoring version; they are `null` for a version
  this API does not know, and `data_quality_breakdown` is then `null`.
- `numerator`/`denominator` reference metric paths of the stored analysis
  result with their counts; the result itself is not repeated (it is
  available from `.../analyses/{run}/result`).
- Never included: source contents, storage keys or URLs, the owner's user
  ID, analyzer internals.

## Competencies

Read-only access to the immutable competency matrices derived from DNA
snapshots (Phase 13, [competency-matrix-v1.md](../architecture/competency-matrix-v1.md)).
Same rules as [DNA](#dna): owner-only (`404 RESOURCE_NOT_FOUND` for another
user's or a missing project, a missing snapshot and a snapshot of another
project), archived projects stay readable, every write method answers
`405`, and nothing is computed per request.

> CodeDNA competency results describe deterministic evidence observed in analyzed source code. They do not establish developer seniority, intelligence, personality, professional level, or future potential.

| Field | Values |
|---|---|
| snapshot `status` | `ASSESSED` (at least one competency assessed) · `INSUFFICIENT_DATA` (none could be) |
| competency `status` | `ASSESSED` · `INSUFFICIENT_EVIDENCE` · `UNSUPPORTED` · `MISSING` (not assessed: `score` and `level` are `null`, never 0) |
| `level` | `NOT_ESTABLISHED` · `DEVELOPING` · `ESTABLISHED` · `STRONG`: evidence levels of the analyzed code, not seniority |
| `score`, `evidence_quality` | 4-place decimal strings on 0–1, as stored |

A project without a matrix lists `"data": []` (there is no fabricated
result).

### `GET /api/v1/projects/{project}/competencies`

Newest first (`created_at`, then `id`), paginated (`?page`, `?per_page` ≤ 100):
`id`, `type: "competency_snapshot"`, `project_id`, `dna_snapshot_id`,
`analysis_run_id`, `source_snapshot_id`, `status`, `competency_version`,
`dna_scoring_version`, `summary` (`competencies`, counts per `statuses` and
`levels`), `created_at`.

### `GET /api/v1/projects/{project}/competencies/{snapshot}`

```json
{ "data": {
  "id": "…", "type": "competency_snapshot", "project_id": "…", "dna_snapshot_id": "…", "analysis_run_id": "…",
  "source_snapshot_id": "…", "status": "ASSESSED", "competency_version": "1.0.0",
  "specification_fingerprint": "<64 hex>", "dna_scoring_version": "1.0.0", "created_at": "…",
  "levels": [ { "level": "NOT_ESTABLISHED", "name": "Not established", "ordinal": 0, "minimum_score": "0.0000" }, … ],
  "summary": { "competencies": 4, "statuses": { "ASSESSED": 4, … }, "levels": { "STRONG": 2, … } },
  "languages": ["php", "python"],
  "dna_snapshot": { "id": "…", "status": "READY", "overall_score": "0.8050", "data_quality": "0.9000",
                    "scoring_version": "1.0.0", "specification_fingerprint": "<64 hex>", "created_at": "…" },
  "source_snapshot": { "id": "…", "version": 3, "file_count": 12, "primary_language": "php", "created_at": "…" },
  "analysis_run": { "id": "…", "result_type": "static_analysis", "status": "SUCCEEDED", "completed_at": "…" },
  "competencies": [
    { "key": "COMPLEXITY_MANAGEMENT", "name": "Complexity management", "description": "…",
      "status": "ASSESSED", "score": "0.7500", "level": "ESTABLISHED", "evidence_quality": "0.9000",
      "evidence_quality_terms": { "parse_coverage": "0.9000", "evidence_volume": "0.8000", "evidence_availability": "1.0000" },
      "limitations": [],
      "evidence": [
        { "source": "COMPLEXITY.mean_cyclomatic_complexity", "dimension": "COMPLEXITY", "component": "mean_cyclomatic_complexity",
          "rationale": "…", "share": false, "weight": "0.6000", "required": true, "status": "AVAILABLE",
          "value": "4.0000", "score": "0.7500", "best": "2.0000", "worst": "10.0000", "minimum_denominator": 5,
          "numerator": [ { "metric": "metrics.overall.complexity_total", "value": 160 } ],
          "denominator": [ { "metric": "metrics.overall.functions_total", "value": 40 } ] } ] } ] } }
```

- `competencies` and `evidence` follow the specification's order; metric
  lists are sorted by path.
- `limitations` lists measured languages for which the evidence is only
  partially supported (e.g. C/C++ without preprocessing); it never changes
  a score.
- `description`, `rationale`, `share` and `levels` come from the
  snapshot's own competency and scoring versions (`null` when unknown).

## Skill gaps

Read-only access to the immutable skill gap analyses that compare a
competency matrix with a versioned, server-owned target profile (Phase 14,
[skill-gap-v1.md](../architecture/skill-gap-v1.md)). Same rules as
[Competencies](#competencies): owner-only (`404` for another user's or a
missing project or snapshot, and for a snapshot of another project),
archived projects stay readable, every write method answers `405`, and no
target, gap, priority or version is ever accepted from a client.

> Skill Gap results describe measurable differences between observed source-code competency evidence and a versioned target definition. They do not establish developer seniority, intelligence, personality, professional worth, or future potential.

| Field | Values |
|---|---|
| snapshot `status` | `GAPS_IDENTIFIED` · `NO_MATERIAL_GAPS` (measured, none material) · `INSUFFICIENT_DATA` (nothing measured) |
| result `status` | `GAP` · `NO_GAP` · `INSUFFICIENT_EVIDENCE` · `UNSUPPORTED` · `MISSING` · `NOT_TARGETED` |
| `raw_gap` | `max(target − current, 0)` as a 4-place string; `null` unless `GAP`/`NO_GAP` (never "target − 0") |
| `priority` | `LOW` · `MEDIUM` · `HIGH`, only for `GAP`; `priority_capped: true` when HIGH was capped for low evidence quality |

A project without an analysis lists `"data": []`.

### `GET /api/v1/projects/{project}/skill-gaps`

Newest first (`created_at`, then `id`), paginated (`?page`, `?per_page` ≤ 100):
`id`, `type: "skill_gap_snapshot"`, `project_id`, `competency_snapshot_id`,
`dna_snapshot_id`, `analysis_run_id`, `source_snapshot_id`, `status`,
`skill_gap_version`, `target_profile` (`key`, `version`),
`competency_version`, `summary` (`competencies`, `material_gaps`, counts per
`statuses` and `priorities`; no aggregate gap), `created_at`.

### `GET /api/v1/projects/{project}/skill-gaps/{snapshot}`

```json
{ "data": {
  "id": "…", "type": "skill_gap_snapshot", "project_id": "…", "competency_snapshot_id": "…", "dna_snapshot_id": "…",
  "analysis_run_id": "…", "source_snapshot_id": "…", "status": "GAPS_IDENTIFIED", "skill_gap_version": "1.0.0",
  "specification_fingerprint": "<64 hex>",
  "target_profile": { "key": "ENGINEERING_STANDARD", "version": "1.0.0", "description": "…" },
  "thresholds": { "material_gap": "0.0500",
                  "priorities": [ { "priority": "LOW", "minimum_gap": "0.0500" }, { "priority": "MEDIUM", "minimum_gap": "0.1500" },
                                  { "priority": "HIGH", "minimum_gap": "0.3000" } ],
                  "high_priority_minimum_evidence_quality": "0.6000" },
  "competency_version": "1.0.0", "dna_scoring_version": "1.0.0", "created_at": "…",
  "summary": { "competencies": 4, "material_gaps": 1, "statuses": { "GAP": 1, "NO_GAP": 3, … }, "priorities": { "HIGH": 1, … } },
  "languages": ["php", "python"],
  "competency_snapshot": { "id": "…", "status": "ASSESSED", "specification_fingerprint": "<64 hex>", "created_at": "…" },
  "source_snapshot": { "id": "…", "version": 3, "file_count": 12, "primary_language": "php", "created_at": "…" },
  "results": [
    { "competency_key": "CODE_HYGIENE", "name": "Code hygiene", "status": "GAP",
      "current_score": "0.6000", "target_score": "0.9000", "raw_gap": "0.3000", "material_gap": true,
      "priority": "HIGH", "priority_capped": false, "evidence_quality": "0.9000",
      "competency_status": "ASSESSED", "current_level": "DEVELOPING", "target_rationale": "…",
      "limitations": [],
      "evidence": [ { "source": "CODE_HYGIENE.syntax_error_share", "status": "AVAILABLE", "value": "0.1000", "score": "0.6000" } ] } ] } }
```

`results` list targeted competencies in the profile's order, then untargeted
ones by key.

## AI assessments

AI-generated, **non-authoritative** interpretations of a skill gap analysis
(Phase 15, [ai-assessment-v1.md](../architecture/ai-assessment-v1.md)). The
AI explains the stored deterministic results; it never determines or
changes any score, level, gap, priority or target, and its output contains
none.

- Owner-only: `404` for another user's or a missing project or assessment,
  and for an assessment of another project.
- Archived projects keep their assessments readable but cannot request new
  ones (`409 PROJECT_ARCHIVED`).
- There is no update or delete (`405`).

### `POST /api/v1/projects/{project}/assessments`

```json
{}
```

or

```json
{ "skill_gap_snapshot_id": "<ULID of a skill gap snapshot of this project>" }
```

The body selects a skill gap snapshot; the default is the newest one. It is
the only accepted field: a prompt, model, provider, score, target, evidence,
instruction or any other field answers `422 VALIDATION_FAILED`, and unknown
field names are not echoed. The request only queues work. No AI provider is
called during it.

| Response | When |
|---|---|
| `202` + assessment (`QUEUED`) | New assessment recorded; generation job dispatched |
| `200` + assessment, `Idempotent-Replayed: true` | An assessment with the same identity is `QUEUED`, `RUNNING` or `SUCCEEDED` |
| `409 AI_ASSESSMENT_DISABLED` | AI is not enabled on the server (`AI_ENABLED=false`, the default) |
| `409 ASSESSMENT_EVIDENCE_UNAVAILABLE` | The project has no skill gap snapshot, or its stored evidence does not match its version |
| `409 ASSESSMENT_INPUT_TOO_LARGE` | The evidence exceeds `AI_MAX_INPUT_BYTES` |
| `409 PROJECT_ARCHIVED` | The project is archived |
| `422 VALIDATION_FAILED` | Another field, or a snapshot that is not this project's |
| `429 RATE_LIMITED` | `assessment-create` limit |

- **Identity:** (project, input fingerprint, assessment version, prompt
  fingerprint, provider, model).
- **After a `FAILED` assessment,** the same request creates a new one.

### `GET /api/v1/projects/{project}/assessments`

The list is ordered newest first (`created_at`, then `id`) and paginated
(`?page`, `?per_page` ≤ 100). Each item has:

- `id` and `type: "ai_assessment"`;
- `project_id` and `skill_gap_snapshot_id`;
- `status`: `QUEUED`, `RUNNING`, `SUCCEEDED` or `FAILED`;
- `assessment_version`, `provider` and `model`;
- `failure`: `{code, message}` or `null`;
- `created_at` and `completed_at`.

The list never includes the input or output.

### `GET /api/v1/projects/{project}/assessments/{assessment}`

```json
{ "data": {
  "id": "…", "type": "ai_assessment", "project_id": "…", "status": "SUCCEEDED",
  "notice": "AI-generated interpretation of the deterministic results. It does not determine or change any score, level, gap, priority or target.",
  "lineage": { "skill_gap_snapshot_id": "…", "competency_snapshot_id": "…", "dna_snapshot_id": "…", "analysis_run_id": "…", "source_snapshot_id": "…" },
  "versions": { "assessment": "1.0.0", "input_schema": "assessment-input/1.0.0", "output_schema": "assessment/v1", "prompt": "1.0.0",
                "dna_scoring": "1.0.0", "competency": "1.0.0", "skill_gap": "1.0.0",
                "target_profile": "ENGINEERING_STANDARD", "target_profile_version": "1.0.0" },
  "fingerprints": { "specification": "<64 hex>", "prompt": "<64 hex>", "input": "<64 hex>", "output": "<64 hex>" },
  "provider": { "name": "openai_compatible", "model": "…", "served_model": "…" },
  "attempts": 1,
  "output": {
    "schema_version": "assessment/v1",
    "summary": { "text": "…", "evidence_refs": ["gap:FUNCTION_DESIGN", "gap:CODE_HYGIENE"] },
    "strengths": [ { "title": "…", "description": "…", "evidence_refs": ["competency:FUNCTION_DESIGN"] } ],
    "areas_to_improve": [ { "title": "…", "description": "…", "evidence_refs": ["gap:CODE_HYGIENE"] } ],
    "development_insights": [],
    "limitations": [ { "description": "…", "evidence_refs": ["quality:data"] } ] },
  "evidence": [
    { "id": "gap:CODE_HYGIENE", "kind": "gap", "label": "Gap: Code hygiene", "description": "…",
      "facts": { "status": "GAP", "current_score": "0.6000", "target_score": "0.9000", "raw_gap": "0.3000", "material_gap": true,
                 "priority": "HIGH", "priority_capped": false, "competency": "competency:CODE_HYGIENE" } } ],
  "failure": null,
  "created_at": "…", "started_at": "…", "completed_at": "…" } }
```

- `output` is present only for `SUCCEEDED`.
- `evidence` is the catalog the interpretation was built from, so every
  `evidence_refs` entry can be resolved.
- A `FAILED` assessment has `output: null` and a fixed
  `failure: {code, message}`. Codes: `PROVIDER_TIMEOUT`,
  `PROVIDER_RATE_LIMITED`, `PROVIDER_UNAVAILABLE`, `PROVIDER_AUTH_FAILED`,
  `PROVIDER_REJECTED`, `OUTPUT_TOO_LARGE`, `INVALID_OUTPUT`,
  `INPUT_TOO_LARGE`, `EVIDENCE_CHANGED`, `EVIDENCE_INVALID`,
  `ASSESSMENT_STALE` and `ASSESSMENT_FAILED`.
- Responses never contain the prompt, the provider response, keys, headers
  or lease data.

## Coding challenges

Practice exercises selected from a skill gap analysis (Phase 16,
[coding-challenges-v1.md](../architecture/coding-challenges-v1.md)). They are
a **practice layer**: assigning, submitting or passing a challenge never
changes a DNA score, competency, skill gap, priority, target or snapshot. No AI
is involved. Submitted code runs only in the isolated evaluator
([challenge-evaluator.md](../architecture/challenge-evaluator.md)), never in
the API.

- Owner-only: `404` for a missing project, another user's project, and a
  challenge or submission of another project (no existence leaks).
- Archived projects keep their challenges readable, but assignment and
  submission answer `409 PROJECT_ARCHIVED`.
- There is no update or delete (`405`). Challenges and attempts are
  immutable records.

### `POST /api/v1/projects/{project}/challenges`

```json
{}
```

or

```json
{ "skill_gap_snapshot_id": "<ULID>", "competency_key": "FUNCTION_DESIGN" }
```

The body only selects which stored gap to practice. The default is the
newest skill gap snapshot and the selector's top gap. The challenge itself
always comes from the server's catalog: a definition, test, command, image,
runtime, difficulty or any other field answers `422 VALIDATION_FAILED`, and
unknown field names are not echoed.

| Response | When |
|---|---|
| `201` + challenge | A new challenge was selected and assigned |
| `200` + challenge, `Idempotent-Replayed: true` | An active (`ASSIGNED` or `EVALUATING`) challenge exists for that snapshot and competency, or, without `competency_key`, for that snapshot |
| `409 CHALLENGES_DISABLED` | `CHALLENGE_ENABLED=false` |
| `409 CHALLENGE_NO_ELIGIBLE_GAP` | No skill gap snapshot, or no gap in a competency with challenges |
| `409 CHALLENGE_NONE_AVAILABLE` | Every matching challenge was already assigned for this snapshot |
| `409 PROJECT_ARCHIVED` | The project is archived |
| `422 VALIDATION_FAILED` | Another field, an unknown competency, or a snapshot that is not this project's |
| `429 RATE_LIMITED` | `challenge-assign` limit |

### `GET /api/v1/projects/{project}/challenges`

The list is ordered newest first and paginated (`?page`, `?per_page` ≤ 100).
Each item has:

- `id` and `type: "challenge"`;
- `project_id`, `skill_gap_snapshot_id` and `competency_key`;
- `definition`: `{key, version, title}`;
- `difficulty` (`BEGINNER`, `INTERMEDIATE` or `ADVANCED`, describing the
  exercise and never the developer) and `language`;
- `status` (`ASSIGNED`, `EVALUATING`, `PASSED` or `FAILED`);
- `attempts_used`, `max_attempts` and `last_result`;
- `created_at` and `closed_at`.

### `GET /api/v1/projects/{project}/challenges/{challenge}`

```json
{ "data": {
  "id": "…", "type": "challenge", "status": "ASSIGNED", "competency_key": "FUNCTION_DESIGN", "difficulty": "BEGINNER",
  "language": "python", "attempts_used": 0, "max_attempts": 5, "last_result": null,
  "notice": "Completing this challenge does not immediately change your CodeDNA score or skill gap. Reassessment occurs from new code analysis.",
  "evaluation_available": true,
  "challenge": { "key": "FUNCTION_DESIGN_001", "version": "1.0.0", "category": "FUNCTION_DESIGN", "difficulty": "BEGINNER",
                 "language": "python", "runtime": "python3.11", "estimated_minutes": 30, "title": "…", "summary": "…",
                 "instructions": ["…"], "constraints": ["…"], "entrypoint": "summarize_order", "starter_code": "def …",
                 "acceptance_criteria": [ { "id": "AC1", "description": "…", "checks": ["tests:visible"] } ],
                 "rules": { "syntax_valid": true, "max_function_lines": 15, "max_function_parameters": 3 },
                 "examples": [ { "id": "v1", "description": "…", "args": [ … ], "expected": … } ],
                 "hidden_case_count": 4 },
  "selection": { "selection_version": "challenge-selection/1.0.0", "rule": "TOP_PRIORITY_GAP", "eligible_gaps": ["FUNCTION_DESIGN"], "gap_rank": 1,
                 "gap": { … }, "preferred_difficulty": "BEGINNER", "selected_difficulty": "BEGINNER", "excluded_definitions": [], "previously_passed": [], … },
  "gap": { "competency_key": "FUNCTION_DESIGN", "status": "GAP", "priority": "HIGH", "raw_gap": "0.3000", "current_score": "…", "target_score": "…", "priority_capped": false },
  "lineage": { "skill_gap_snapshot_id": "…", "competency_snapshot_id": "…", "dna_snapshot_id": "…", "analysis_run_id": "…", "source_snapshot_id": "…" },
  "versions": { "definition": "1.0.0", "catalog": "1.0.0", "selection": "challenge-selection/1.0.0", "evaluation": "challenge-evaluation/1.0.0" },
  "fingerprints": { "catalog": "<64 hex>", "definition": "<64 hex>", "test_suite": "<64 hex>" },
  "recent_attempts": [ ],
  "created_at": "…", "updated_at": "…", "closed_at": null } }
```

- `examples` are the visible test cases. Hidden cases are only counted:
  their arguments and expected values are never returned.
- `evaluation_available` is `false` when challenges are disabled or no
  evaluator is reachable. Submissions are refused then.
- `recent_attempts` holds the newest 20 attempts as list items. Use the
  submissions list for older ones.

### `POST /api/v1/projects/{project}/challenges/{challenge}/submissions`

```http
Idempotency-Key: <optional, 8–128 of A-Z a-z 0-9 . _ : ->

{ "language": "python", "source": "def summarize_order(order):\n    …" }
```

The source is stored exactly as sent (no trimming). It must be:

- UTF-8, with no NUL characters and not blank;
- at most `CHALLENGE_MAX_SOURCE_BYTES` (16384) bytes and
  `CHALLENGE_MAX_SOURCE_LINES` (400) lines.

Any other field answers `422 VALIDATION_FAILED`. The request only records the
attempt and queues its evaluation. Nothing is executed during the request.

| Response | When |
|---|---|
| `202` + submission (`QUEUED`) | A new attempt was recorded and queued |
| `200` + submission, `Idempotent-Replayed: true` | Same `Idempotency-Key` and same source as an earlier attempt |
| `409 CHALLENGES_DISABLED` | `CHALLENGE_ENABLED=false` |
| `409 CHALLENGE_EVALUATION_UNAVAILABLE` | No evaluator is configured or reachable. The code is not run anywhere else |
| `409 CHALLENGE_EVALUATION_PENDING` | An earlier attempt is still being evaluated |
| `409 CHALLENGE_CLOSED` | The challenge is `PASSED`, or `FAILED` with its attempts used up |
| `409 PROJECT_ARCHIVED` | The project is archived |
| `422 IDEMPOTENCY_KEY_REUSED` | The key was already used with different source |
| `422 VALIDATION_FAILED` | Invalid source, a language other than the challenge's, or another field |
| `429 RATE_LIMITED` | `challenge-submit` limit |

### `GET /api/v1/projects/{project}/challenges/{challenge}/submissions`

The list is ordered newest first and paginated. Each item has:

- `id` and `type: "challenge_submission"`;
- `challenge_id`, `attempt_number` and `language`;
- `status` (`QUEUED`, `RUNNING`, `PASSED`, `FAILED` or `ERROR`);
- `source_bytes` and `source_sha256`;
- `tests`: `{passed, total, visible, hidden}` or `null`;
- `execution_status`;
- `failure`: `{code, message}` or `null`;
- `created_at` and `completed_at`.

The list never includes the source.

### `GET /api/v1/projects/{project}/challenges/{challenge}/submissions/{submission}`

The list fields, plus:

- `notice`, `source`, `duration_ms` and `started_at`;
- `versions`: `{evaluation, evaluator, runtime}`;
- `fingerprints`: `{definition, test_suite, evaluation}`;
- `evaluation`, once graded:
  - `verdict`: `PASSED` or `FAILED`;
  - `execution`: `{status, message, load_error}`;
  - `tests`: counts;
  - `criteria`: `[ {id, description, status} ]`;
  - `rules`: `[ {rule, limit, status, observed?, violations?, line?, not_evaluated?} ]`;
  - `cases`: visible cases with `args`, `expected` and `observed`; hidden
    cases with `id`, `status` and `error` only.

`ERROR` attempts (evaluator unavailable, timed out, interrupted, rejected,
invalid result or changed definition, stuck and ended by the stale sweeper) have `evaluation: null` and a fixed
`failure`. Their codes are `EVALUATOR_UNAVAILABLE`, `EVALUATION_TIMEOUT`,
`EVALUATION_INTERRUPTED`, `EVALUATION_REJECTED`, `EVALUATION_INVALID`,
`EVALUATION_STALE` and `EVALUATION_FAILED`. An `ERROR` attempt does not count
against `max_attempts`.

Responses never contain hidden test inputs or expected values, evaluator
internals, lease data or the idempotency key.

## Learning roadmaps

What to work on next, generated deterministically from the newest skill gap
analysis (Phase 17,
[learning-roadmap-v1.md](../architecture/learning-roadmap-v1.md)). It is a
**planning layer**: generating a roadmap or completing its steps never
changes a DNA score, competency, skill gap, priority, target or snapshot,
and never starts an analysis. No AI is involved.

- Owner-only: `404` for a missing project, another user's project, a
  roadmap of another project, and an unknown step.
- Archived projects keep their roadmaps readable, but generation and step
  completion answer `409 PROJECT_ARCHIVED`.
- There is no update or delete (`405`).

### `POST /api/v1/projects/{project}/roadmaps`

```json
{}
```

The body must be empty. Tracks, steps, targets, gaps, priorities, versions
and URLs are all server-owned, so any field answers `422 VALIDATION_FAILED`
(field names are not echoed). The roadmap is generated from the project's
newest skill gap snapshot.

| Response | When |
|---|---|
| `201` + roadmap | A new roadmap was generated. The project's previous `ACTIVE` roadmap became `SUPERSEDED` |
| `200` + roadmap, `Idempotent-Replayed: true` | A roadmap for the newest snapshot and the current versions already exists, whatever its status |
| `409 ROADMAP_NO_SKILL_GAPS` | The project has no skill gap analysis |
| `409 ROADMAP_NO_ACTIONABLE_GAPS` | The newest analysis has no measured, material gap with a track. An existing roadmap is left as it is |
| `409 ROADMAP_EVIDENCE_INVALID` | The newest analysis does not match its skill gap specification |
| `409 PROJECT_ARCHIVED` | The project is archived |
| `422 VALIDATION_FAILED` | The body was not empty |
| `429 RATE_LIMITED` | `roadmap-generate` limit |

### `GET /api/v1/projects/{project}/roadmaps`

The list is ordered newest first and paginated (`?page`, `?per_page` ≤ 100).
Each item has:

- `id` and `type: "learning_roadmap"`;
- `project_id` and `skill_gap_snapshot_id`;
- `status`: `ACTIVE`, `COMPLETED` or `SUPERSEDED`;
- `focus`: the focus competencies, in order;
- `progress`: `{completed, total}` steps;
- `estimated_minutes`;
- `versions`: `{roadmap, rules}`;
- `created_at`, `superseded_at` and `completed_at`.

### `GET /api/v1/projects/{project}/roadmaps/{roadmap}`

```json
{ "data": {
  "id": "…", "type": "learning_roadmap", "status": "ACTIVE", "focus": ["TYPE_STRUCTURE", "COMPLEXITY_MANAGEMENT", "FUNCTION_DESIGN"],
  "progress": { "completed": 1, "total": 21 }, "estimated_minutes": 720,
  "notice": "Completing learning steps does not change your CodeDNA score or skill gap. Improvement is measured through new code analysis.",
  "development_focus": {
    "selected": [ { "competency_key": "TYPE_STRUCTURE", "status": "GAP", "priority": "HIGH", "priority_capped": false,
                    "current_score": "0.0000", "target_score": "0.7500", "raw_gap": "0.7500", "evidence_quality": "0.9000",
                    "current_level": "NOT_ESTABLISHED", "rank": 1, "ranked_above": "COMPLEXITY_MANAGEMENT", "deciding_criterion": "RAW_GAP" }, … ],
    "excluded": [ { "competency_key": "CODE_HYGIENE", "status": "GAP", …, "rank": 4, "reason": "TRACK_LIMIT" } ] },
  "tracks": [ { "position": 1, "key": "TYPE_STRUCTURE", "version": "1.0.0", "competency_key": "TYPE_STRUCTURE",
                "title": "Structure types around one responsibility", "description": "…", "objective": "…", "estimated_minutes": 230,
                "focus": { … }, "progress": { "completed": 1, "total": 6 },
                "steps": [ { "key": "ts-responsibility", "position": 1, "type": "READ", "title": "…", "description": "…", "objective": "…",
                             "estimated_minutes": 15, "prerequisites": [], "completed_at": "…", "can_complete": false,
                             "challenge": null, "practice": null },
                           { "key": "ts-challenge", "position": 5, "type": "CHALLENGE", …,
                             "challenge": { "key": "TYPE_STRUCTURE_001", "version": "1.0.0", "title": "Split the inventory type",
                                            "difficulty": "INTERMEDIATE", "in_catalog": true },
                             "practice": { "challenge_id": "…", "definition_key": "TYPE_STRUCTURE_001", "status": "ASSIGNED" } }, … ] }, … ],
  "lineage": { "skill_gap_snapshot_id": "…", "competency_snapshot_id": "…", "dna_snapshot_id": "…", "analysis_run_id": "…", "source_snapshot_id": "…" },
  "versions": { "roadmap": "1.0.0", "rules": "1.0.0", "skill_gap": "1.0.0", "target_profile": { "key": "ENGINEERING_STANDARD", "version": "1.0.0" },
                "challenge_catalog": "1.0.0" },
  "fingerprints": { "roadmap": "<64 hex>", "catalog": "<64 hex>", "rules": "<64 hex>", "skill_gap_specification": "<64 hex>", "challenge_catalog": "<64 hex>" },
  "current": { "catalog": true, "rules": true },
  "superseded_by": null, "created_at": "…", "superseded_at": null, "completed_at": null, "updated_at": "…" } }
```

- **Focus values** are the skill gap snapshot's values as stored when the
  roadmap was generated.
- **`deciding_criterion`** names the first criterion (`PRIORITY`,
  `RAW_GAP`, `EVIDENCE_QUALITY` or `COMPETENCY_KEY`) that ranks the entry
  above `ranked_above`.
- **`reason`** on an excluded entry is one of `NO_GAP`,
  `INSUFFICIENT_EVIDENCE`, `UNSUPPORTED`, `MISSING`, `NOT_TARGETED`,
  `NO_TRACK` or `TRACK_LIMIT`.
- **`can_complete`** is true for an `ACTIVE` roadmap's uncompleted step
  whose prerequisites are completed.
- **`challenge`** is the recommended Phase 16 challenge of a `CHALLENGE`
  step, or `null` when none was available.
- **`practice`** is the developer's newest challenge for this snapshot and
  competency, if one was assigned.
- **`current`** says whether the server still uses the catalog and rules
  this roadmap was generated with. A roadmap never changes either way.
- **Never exposed:** owner IDs, storage keys, URLs and analyzer payloads.

### `POST /api/v1/projects/{project}/roadmaps/{roadmap}/steps/{step}/complete`

The step key comes from the roadmap, for example `fd-reduce-length`. The
body must be empty: no completion date, score or status can be sent. This
records learning progress only.

| Response | When |
|---|---|
| `200` + roadmap | The step was completed. Completing the last step makes the roadmap `COMPLETED` |
| `200` + roadmap, `Idempotent-Replayed: true` | The step was already completed |
| `404 RESOURCE_NOT_FOUND` | Unknown step, or a roadmap of another project or user |
| `409 ROADMAP_NOT_ACTIVE` | The roadmap is `SUPERSEDED` or `COMPLETED` |
| `409 ROADMAP_STEP_PREREQUISITES_INCOMPLETE` | A step this one depends on is not completed |
| `409 PROJECT_ARCHIVED` | The project is archived |
| `422 VALIDATION_FAILED` | The body was not empty |
| `429 RATE_LIMITED` | `roadmap-progress` limit |

## Authentication (browser, Sanctum SPA)

Browsers authenticate with Laravel's **session cookie**. No token is ever
given to JavaScript or stored in `localStorage` or `sessionStorage`
([ADR-006](../decisions/ADR-006-authentication.md)).

```text
1. GET  /sanctum/csrf-cookie            → 204, sets XSRF-TOKEN (readable) + codedna-session (HttpOnly)
2. POST /api/v1/auth/login              header X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>
   (or /auth/register)                  → 200/201, session regenerated
3. GET  /api/v1/me                      cookies sent automatically → 200
4. POST /api/v1/auth/logout             header X-XSRF-TOKEN → 204, session invalidated
```

Requirements for a request to get a session ("stateful"):

- Its `Origin` or `Referer` host must be in `SANCTUM_STATEFUL_DOMAINS`
  (locally `localhost`, plus `localhost:3000` for the split-origin
  fallback).
- Same-origin `fetch` sends cookies by default. Cross-origin setups need
  `credentials: "include"`.
- `register`, `login`, `logout` and `auth/password` **require** a session.
  A request without one gets `400 BAD_REQUEST`.

### Cookies

| Cookie | HttpOnly | Purpose |
|---|---|---|
| `codedna-session` | **yes** (always) | Session ID. Session data lives in Redis |
| `XSRF-TOKEN` | no, by design | CSRF token for the client to echo in `X-XSRF-TOKEN`. It is not a credential |

Both cookies use `SameSite=Lax`, and `Secure` when `SESSION_SECURE_COOKIE`
is set (required in production, enforced at boot). They are host-only
unless `SESSION_DOMAIN` is set.

### CSRF

Every state-changing request (`POST`, `PUT`, `PATCH`, `DELETE`) from a
browser must send `X-XSRF-TOKEN`. Laravel 13 additionally accepts requests
that the browser itself marks `Sec-Fetch-Site: same-origin`. Cross-site
requests always need the token (see ADR-006). Otherwise the response is `419
CSRF_TOKEN_MISMATCH`, and the client should call `/sanctum/csrf-cookie` and
retry. CSRF protection is never disabled. `make verify` checks enforcement
through Nginx, because Laravel skips it inside PHPUnit.

### Future: token clients

Non-browser clients will use `Authorization: Bearer <token>` (Sanctum
personal access tokens) on the same endpoints and guard. Issuing tokens is
not implemented yet. Any bearer token sent today is rejected with `401`.

### CORS

The browser uses one origin (Nginx), so **CORS is closed**: no
`Access-Control-Allow-Origin` header is sent. Only for the split-origin
fallback, set `CORS_ALLOWED_ORIGINS` to the exact frontend origin(s).
Credentials are then allowed for those origins only. `*` is never used.

## Requests

- `Content-Type: application/json`. Source uploads use
  `multipart/form-data`.
- Send `Accept: application/json`. API routes answer with JSON errors even
  without it.
- Field names are `snake_case`.
- IDs are **ULIDs** (26 characters, lowercase). Database sequences are never
  exposed.
- Timestamps are ISO 8601 UTC with `Z`, e.g. `2026-10-05T12:00:00Z`.
- Request bodies are limited to 55 MB at Nginx and PHP (`413
  PAYLOAD_TOO_LARGE`).

## Responses

A single resource is wrapped in `data`:

```json
{ "data": { "id": "…", "type": "user" } }
```

`204 No Content` responses have no body. Collections are paginated:
`?page=2&per_page=25` (`per_page` at most 100, default 25; invalid values
are `422`). The response carries the page in `data` and its position in
`meta`. No link URLs are returned (they would depend on the server's host
name); clients build page links from `meta`:

```json
{ "data": [ { "id": "…", "type": "project" } ], "meta": { "current_page": 2, "per_page": 25, "total": 60, "last_page": 3 } }
```

## Errors

Every error, on every API route, uses one envelope:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "request_id": "6f1c2a4e-1d3b-4c55-9a7e-2b8f0c9d1e23",
    "details": { "fields": { "email": ["The email has already been taken."] } }
  }
}
```

- `details` is present only when there are details. Today that means
  validation errors, as `details.fields`: field name mapped to a list of
  messages. Archive rejections never include details (no entry names or
  contents).
- `message` is safe to show to users. Exception messages, stack traces,
  file paths and SQL are **never** included, even with `APP_DEBUG=true`.
  They go to the log, together with the `request_id`.

### Error codes

| HTTP | `code` | When |
|---|---|---|
| 400 | `BAD_REQUEST` | Malformed request, or a session endpoint called without a session |
| 401 | `AUTHENTICATION_REQUIRED` | No valid session (or token) |
| 403 | `FORBIDDEN` | Authenticated but not allowed (policy denial) |
| 404 | `RESOURCE_NOT_FOUND` | Unknown route or resource the caller may not see (no existence leaks) |
| 405 | `METHOD_NOT_ALLOWED` | Wrong HTTP method for the route (e.g. `DELETE` on a project) |
| 409 | `PROJECT_ARCHIVED` | The project is archived: no edits, no uploads |
| 409 | `INVALID_SOURCE_TYPE` | Upload to a project whose source type is not `UPLOAD` |
| 409 | `ANALYSIS_NOT_COMPLETED` | The analysis run has no result (it has not succeeded) |
| 409 | `AI_ASSESSMENT_DISABLED` | AI assessment is not enabled on the server |
| 409 | `ASSESSMENT_EVIDENCE_UNAVAILABLE` | No skill gap analysis that can be interpreted |
| 409 | `ASSESSMENT_INPUT_TOO_LARGE` | The evidence exceeds the AI input limit |
| 409 | `CHALLENGES_DISABLED` | Coding challenges are disabled on the server |
| 409 | `CHALLENGE_NO_ELIGIBLE_GAP` | No skill gap in a competency that has challenges |
| 409 | `CHALLENGE_NONE_AVAILABLE` | Every matching challenge was already assigned for the snapshot |
| 409 | `CHALLENGE_EVALUATION_UNAVAILABLE` | No challenge evaluator is available; submitted code is not run |
| 409 | `CHALLENGE_EVALUATION_PENDING` | An earlier attempt is still being evaluated |
| 409 | `CHALLENGE_CLOSED` | The challenge is passed or out of attempts |
| 409 | `ROADMAP_NO_SKILL_GAPS` | The project has no skill gap analysis for a roadmap |
| 409 | `ROADMAP_NO_ACTIONABLE_GAPS` | The newest skill gap analysis has no measurable gap with a learning track |
| 409 | `ROADMAP_EVIDENCE_INVALID` | The newest skill gap analysis does not match its specification |
| 409 | `ROADMAP_NOT_ACTIVE` | The roadmap is superseded or completed; its progress cannot change |
| 409 | `ROADMAP_STEP_PREREQUISITES_INCOMPLETE` | A step this step depends on is not completed |
| 413 | `PAYLOAD_TOO_LARGE` | Body exceeds the Nginx/PHP limit |
| 413 | `SOURCE_ARCHIVE_TOO_LARGE` | Archive over `SOURCE_MAX_ARCHIVE_BYTES` |
| 419 | `CSRF_TOKEN_MISMATCH` | Missing or stale `X-XSRF-TOKEN` |
| 422 | `VALIDATION_FAILED` | Input failed validation (`details.fields`) |
| 422 | `INVALID_CREDENTIALS` | Login with a wrong email/password combination |
| 422 | `IDEMPOTENCY_KEY_REUSED` | `Idempotency-Key` already used for a different archive or challenge source |
| 422 | `SOURCE_ARCHIVE_INVALID` | Not a valid, supported ZIP (wrong format, corrupt, encrypted, no files, ...) |
| 422 | `SOURCE_ARCHIVE_UNSAFE` | Unsafe entry: path traversal, absolute or drive path, symlink, special file, zip bomb, ... |
| 422 | `SOURCE_UNCOMPRESSED_SIZE_EXCEEDED` | Expands beyond `SOURCE_MAX_UNCOMPRESSED_BYTES` |
| 422 | `SOURCE_FILE_COUNT_EXCEEDED` | More files than `SOURCE_MAX_FILES` |
| 422 | `SOURCE_FILE_TOO_LARGE` | A file over `SOURCE_MAX_SINGLE_FILE_BYTES` |
| 429 | `RATE_LIMITED` | Rate limit exceeded (`Retry-After` header) |
| 500 | `INTERNAL_ERROR` | Unexpected failure |
| 503 | `SERVICE_UNAVAILABLE` | Temporarily unavailable (e.g. maintenance) |

The vocabulary is defined in `app/Http/Errors/ErrorCode.php`. Add a code only
when a client must be able to tell the case apart.

## Request IDs

Every response carries `X-Request-ID`. A client-supplied UUID is reused.
Anything else is replaced with a new UUID. The ID appears in error bodies and
in every log line written while handling the request, and is propagated to
queued jobs (Laravel Context).

## Rate limiting

| Limiter | Applies to | Limit | Key |
|---|---|---|---|
| `api` | every `/api/v1` route | 120 / minute | user ID, or IP when anonymous |
| `login` | `POST /auth/login` | 5 / minute **and** 20 / minute | email + IP, **and** IP |
| `register` | `POST /auth/register` | 10 / minute | IP |
| `profile-update` | `PATCH /profile` | 30 / minute | user ID |
| `password-change` | `PATCH /auth/password` | 5 / minute **and** 20 / hour | user ID |
| `project-create` | `POST /projects` | 10 / minute | user ID |
| `project-update` | `PATCH /projects/{project}`, `POST /projects/{project}/archive` | 30 / minute | user ID |
| `source-upload` | `POST /projects/{project}/source-snapshots` | 5 / minute **and** 60 / hour | user ID |
| `analysis-create` | `POST /projects/{project}/analyses` | 10 / minute | user ID |
| `assessment-create` | `POST /projects/{project}/assessments` | 5 / minute **and** 30 / hour | user ID |
| `challenge-assign` | `POST /projects/{project}/challenges` | 10 / minute | user ID |
| `challenge-submit` | `POST /projects/{project}/challenges/{challenge}/submissions` | 10 / minute **and** 60 / hour | user ID |
| `roadmap-generate` | `POST /projects/{project}/roadmaps` | 10 / minute | user ID |
| `roadmap-progress` | `POST /projects/{project}/roadmaps/{roadmap}/steps/{step}/complete` | 60 / minute | user ID |

Every attempt counts, successful or not. When a limit is exceeded the
response is `429 RATE_LIMITED` with `Retry-After`. Throttled routes also
return `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Limits are
configured in `config/codedna.php`.

## Planned endpoints (not implemented)

A developer-wide DNA, competency or skill gap profile across projects
(ADR-004's aggregation) is not implemented; results are served per project
([DNA](#dna), [Competencies](#competencies), [Skill gaps](#skill-gaps)). Future paths
follow the domain model in [data-model.md](../architecture/data-model.md):
projects own snapshots, snapshots have analysis runs, and runs produce DNA
snapshots.
