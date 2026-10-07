# GitHub Integration v1

Phase 19. A project can import its source from a GitHub repository. GitHub
is a **source provider** only: each import becomes an immutable
`SourceSnapshot`, exactly like a ZIP upload. The existing pipeline then
analyzes it:

```text
Project → SourceSnapshot → AnalysisRun → Analyzer → CodeDNA
```

There is no second analysis pipeline, and nothing here runs repository code.

- **Decision:** [ADR-011](../decisions/ADR-011-github-integration.md)
- **Code:**
  - `backend/app/Services/GitHub/` (HTTP client, typed API, settings)
  - `backend/app/Actions/GitHub/`
  - `backend/app/Jobs/ImportGitHubSource.php`
  - `frontend/src/components/github/`

## Principles

1. **SourceSnapshot is the source of truth.**
   - Once imported, an analysis is reproducible from the immutable snapshot.
   - This holds when the repository or branch changes, the repository is
     deleted, the App is uninstalled, the connection is disconnected, or a
     token expires.
2. **Disconnecting never deletes history.**
   - Snapshots, analyses, DNA, competency, skill gap and growth records stay.
   - Only future GitHub access ends.
3. **Repository code is never executed.**
   - There is no `git`, no package manager, no build and no script.
   - The archive is fetched through GitHub's official API and inspected as
     ZIP structures only, by the same `ZipArchiveInspector` and limits as
     uploads.
4. **The client chooses, the server verifies.**
   - A client sends a repository ID and a branch name at most.
   - Owner, name, visibility, installation and commit always come from
     GitHub, read by the server.
   - No URL is ever accepted from a client.
5. **Least privilege.**
   - The App has read-only `Contents` and `Metadata` permissions.
   - Installation tokens are minted per import, limited to one repository
     and those two permissions, and never stored.

## Authentication strategy

CodeDNA uses a **GitHub App**, not an OAuth App and not personal access
tokens (see ADR-011 for why). Identities are kept separate:

```text
CodeDNA user ──(authorizes)──► GitHub user identity   (github_accounts: encrypted user tokens)
GitHub App   ──(installed on)──► account / organization (installation, chosen by the GitHub owner)
CodeDNA project ──(connected to)──► one repository      (github_connections: verified repository + installation)
```

### App configuration

Configure the App on GitHub as follows:

- **Repository permissions:** `Contents: Read-only` and `Metadata: Read-only`.
  Nothing else: no write access, issues, pull requests, administration,
  workflows or organization permissions.
- **"Request user authorization (OAuth) during installation":** enabled.
- **Callback URL:** `GITHUB_CALLBACK_URL`, which is
  `https://<host>/app/github/callback`.
- **"Expire user authorization tokens":** recommended. CodeDNA refreshes
  them, and rotates the refresh token.
- **Webhooks:** none required (see [Webhooks](#webhooks)).

### Credentials and where they are used

| Credential | Lifetime | Stored | Used for |
|---|---|---|---|
| App private key | long | environment / secret store only | signs the App JWT |
| App JWT (RS256) | 9 minutes, `iat` − 60 s | never | finding a repository's installation; minting installation tokens |
| Installation token | 1 hour (GitHub) | never (in memory during one import) | the repository, branch and archive calls of one import |
| User access token | 8 hours (if expiring) | `github_accounts`, encrypted with Laravel Crypt (APP_KEY) | listing installations and repositories; verifying the user can see a repository and branch |
| User refresh token | 6 months | `github_accounts`, encrypted | renewing the user token (under a row lock, because GitHub rotates it) |
| OAuth state | 10 minutes, single use | SHA-256 only (`github_oauth_states`) | binding the callback to the user who started it |

Additional protections:

- **Never exposed.** No token is ever returned by the API, sent to the
  browser, put in a URL, written to a log, put in an exception message or
  serialized into a queue payload (the job carries only the import ID).
- **Hidden.** `GitHubAccount` hides both tokens from serialization.
- **Redacted.** The token value object redacts itself in debug output.

## Authorization

```text
browser                         CodeDNA API                              GitHub
   │ POST /github/authorizations  │                                        │
   │ ───────────────────────────► │ state = 32 random bytes (base64url)    │
   │ ◄── authorize_url, install_url │ store sha256(state), user, +10 min     │
   │ ─────────────── navigate to authorize_url / install_url ──────────────►│
   │ ◄──── /app/github/callback?code=…&state=…[&installation_id=…] ─────────│
   │ POST /github/callback {state, code}                                   │
   │ ───────────────────────────► │ consume state atomically:              │
   │                              │   UPDATE … WHERE hash AND user AND     │
   │                              │   unused AND unexpired RETURNING       │
   │                              │ exchange code (client secret) ────────►│
   │                              │ GET /user ────────────────────────────►│
   │                              │ store encrypted tokens                 │
   │ ◄── {connected, project_id}  │                                        │
```

How the flow is protected:

- **The callback is a frontend page.** GitHub redirects the browser to
  `/app/github/callback`, which POSTs `state` and `code` to the API with the
  session and CSRF token.
  - A top-level navigation from github.com is not a Sanctum stateful
    request, so the API itself cannot be the redirect target.
- **The state is unpredictable, short-lived, single-use and bound to the
  user.**
  - A replay, an expired state, an unknown state and another user's state
    all fail with `GITHUB_STATE_INVALID`.
  - Another user's attempt does not consume the state.
- **Callback parameters other than `state` and `code` are ignored.**
  `installation_id` and `setup_action` are never trusted: installations are
  always read from GitHub as the user.
- **A refused code still consumes its state,** so the user starts again.

## Connection

`POST /projects/{project}/github {repository_id, branch?}` is owner-only,
needs an ACTIVE project, and accepts no other field. The server verifies
everything with GitHub:

1. `GET /repositories/{id}` **with the user's token**. Only a repository the
   user can see through the App is returned. A disabled repository is
   refused (`GITHUB_REPOSITORY_NOT_FOUND`).
2. The branch (default: the repository's default branch) is checked
   locally, then `GET /repos/{owner}/{name}/branches/{branch}`
   (`GITHUB_BRANCH_NOT_FOUND`).
3. `GET /repos/{owner}/{name}/installation` **with the App JWT** finds the
   installation (`GITHUB_INSTALLATION_REQUIRED`).
4. `GET /user/installations` **with the user's token**: that installation
   must be one the user can access (`GITHUB_INSTALLATION_REQUIRED`,
   Phase 21). A public repository is readable with any user token, so
   without this step a user could import through another organization's
   installation. Every import request repeats the check.
5. The connection is stored under the project lock. A unique index allows
   at most one ACTIVE connection per project (`GITHUB_ALREADY_CONNECTED`).

The stored metadata is the repository ID, owner, name, full name,
visibility, archived flag, default branch and installation ID. The
repository is identified by its **numeric ID**, so a renamed repository
stays the same. Metadata is refreshed on branch changes and imports.

Both project types can connect. For a `REPOSITORY` project this is the way
to receive source. `repository_url` stays descriptive.

## Branch selection

The local syntax check is a conservative subset of git's ref rules.
Allowed characters are `A–Z a–z 0–9 . _ / -`, with:

- no leading `-`, `/` or `.`;
- no trailing `/`;
- no `//`, `..`, `/.`, `@{` or `.lock` ending.

A failing name never reaches GitHub. A valid name is URL-encoded per path
component. It is never put into a shell; there is no shell.

- **Listing:** `GET /projects/{project}/github/branches` reads one page from
  GitHub as the user (at most 100 per page and 50 pages). Names CodeDNA
  cannot use safely are left out.
- **Changing:** `PATCH /projects/{project}/github {branch}` verifies the new
  branch with GitHub first.
- **A branch that disappears** makes the next import fail with
  `GITHUB_BRANCH_NOT_FOUND`. CodeDNA never switches to another branch on its
  own.

## Import lifecycle

```text
POST /projects/{p}/github/imports {}
  → project ACTIVE, connection ACTIVE, user still sees the repository (as the user)
  → github_imports row QUEUED (one in progress per project; asking again returns it)
  → ImportGitHubSource job (queue "github", payload: import ID only)

job:
  QUEUED → RUNNING (row lock; a duplicate delivery does nothing)
  connection disconnected? → CANCELLED        project archived? → FAILED PROJECT_ARCHIVED
  installation token: one repository, contents:read + metadata:read
  GET /repositories/{id}                      (identity by ID; metadata refreshed)
  GET /repos/{o}/{r}/branches/{branch}        → commit SHA (40 hex)
  snapshot of (project, repository, SHA) exists? → SUCCEEDED, reused, no download
  GET /repos/{o}/{r}/zipball/{SHA}            → 302 Location (never followed automatically)
  Location origin ∈ GITHUB_ARCHIVE_ORIGINS?   else FAILED (never fetched)
  GET Location (no Authorization header), Content-Type application/zip, streamed to a temp file, capped at the archive limit
  ZipArchiveInspector (same limits as uploads)  → SOURCE_ARCHIVE_* on rejection
  ZIP comment, if a commit ID, must equal SHA
  store privately: {prefix}projects/{project}/snapshots/{snapshot}/source.zip
  under the project lock: RecordSourceSnapshot (REPOSITORY, provenance) or reuse; SUCCEEDED; connection.last_imported_*
```

| Status | Meaning |
|---|---|
| `QUEUED` | Waiting for a worker |
| `RUNNING` | Claimed by one worker |
| `SUCCEEDED` | `source_snapshot_id` and `commit_sha` set; `created_snapshot` is false when the snapshot was reused |
| `FAILED` | `failure_code` is a safe code (below); no snapshot, no stored object |
| `CANCELLED` | The connection was disconnected before the import recorded anything |

Failure handling:

- **One attempt.** GitHub failures are recorded on the import, and the
  owner imports again.
- **Worker death.** A worker timeout or crash marks the import `FAILED`
  (`failed()` hook).
- **Stale imports.** An import untouched for
  `GITHUB_IMPORT_STALE_AFTER_SECONDS` (15 minutes) is failed by the next
  request, so it never blocks.
- **No automatic analysis.** The import starts no analysis. The page says
  "Source imported. Ready for analysis.", and
  `POST /projects/{project}/analyses` stays the only way to analyze.

### Idempotency

The key is (project, repository ID, commit SHA), the strictest form of
"same project, repository, ref and commit":

- **Same commit, any branch:** a repeat reuses the snapshot. Nothing is
  downloaded or stored again.
- **New commit:** a new immutable snapshot (the next version).
- **Database backstops:**
  - the partial unique index `github_imports_commit_unique` on
    (project_id, repository_id, commit_sha) WHERE created_snapshot;
  - the partial unique index `github_imports_one_active_unique` (one import
    in progress per project);
  - the row lock in the claim.
- **A concurrent winner:** a snapshot recorded by another import while this
  one downloaded is reused, and this import's object is deleted.

### Failure codes

| Code | When |
|---|---|
| `GITHUB_AUTH_REQUIRED` | No user authorization, or GitHub refused the token or refresh |
| `GITHUB_INSTALLATION_REQUIRED` | The App is not (or no longer) installed for the repository |
| `GITHUB_REPOSITORY_NOT_FOUND` | Not visible through the App (deleted, access removed, disabled) |
| `GITHUB_BRANCH_NOT_FOUND` | The connected branch no longer exists |
| `GITHUB_RATE_LIMITED` | 429, or 403 with no remaining quota |
| `GITHUB_UNAVAILABLE` | Timeouts, connection failures, 5xx |
| `GITHUB_IMPORT_FAILED` | Anything else: malformed responses, a rejected redirect, a wrong content type |
| `SOURCE_ARCHIVE_*` | The archive failed the Phase 07 checks: invalid, unsafe (path traversal, symlinks), too large, too many files, a file too large, expands too far |
| `PROJECT_ARCHIVED` | The project was archived before the import finished |

GitHub's messages, headers and bodies are never stored, returned or logged.
Logs record the operation, the failure kind and the HTTP status.

## GitHub client

`GitHubHttp` is the only place CodeDNA talks to GitHub. `GitHubApi` builds
typed calls on top of it.

| Aspect | Behavior |
|---|---|
| URLs | `GITHUB_API_URL` / `GITHUB_WEB_URL` + a path from validated, encoded identifiers. Never a URL from a request or a response, except the archive redirect, checked against `GITHUB_ARCHIVE_ORIGINS` (exact scheme, host and port; no user info) |
| Redirects | Never followed for API calls. The one archive redirect is read and validated by hand |
| Timeouts | Connect 5 s, request 15 s, download 120 s; the job timeout (180 s) exceeds them and stays below the queue's `retry_after` (validated at boot) |
| Bodies | Read as a stream; JSON over 4 MiB, or an archive over the archive limit, is refused |
| Retries | One retry of an idempotent GET after a connection failure or 502/503/504. Never for POST, 4xx or rate limits |
| Rate limits | 429, or 403 with `X-RateLimit-Remaining: 0` / `Retry-After`, become `RateLimited` with the delay (clamped to 1–3600 s). The API answers `429 GITHUB_RATE_LIMITED` with `Retry-After` |
| Responses | Every field used is type- and syntax-checked (`GitHubRepository::fromApi`, branch and SHA formats); anything else is `InvalidResponse` |
| Pagination | `per_page` ≤ 100; the next page is known from `Link: rel="next"` |

## Webhooks

None. The chosen architecture does not need them:

- **Authorization:** the installation flow returns through OAuth.
- **Uninstalled or revoked access:** this surfaces on the next request as
  `GITHUB_INSTALLATION_REQUIRED` or `GITHUB_REPOSITORY_NOT_FOUND`.

The model leaves room for a future webhook phase:

- connections have stable repository and installation IDs;
- imports have a provider-neutral lifecycle;
- a push event could queue the same `RequestGitHubImport`.

Such a phase would need signature verification (HMAC-SHA256 with the
webhook secret), replay protection and an event allowlist.

## Data model

There are four tables in one additive migration
(`2026_10_16_000001_create_github_tables.php`); see
[data-model.md](data-model.md#github_accounts-github_oauth_states-github_connections-github_imports).

- **No source code** is stored outside object storage.
- **No foreign key cascades.**
- **Composite foreign keys** keep project and owner lineage:
  - connections → `projects (id, user_id)`;
  - imports → `github_connections (id, project_id, user_id)`;
  - imports → `source_snapshots (id, project_id)`;
  - states → `projects (id, user_id)`.
- **Triggers:**
  - A connection's identity never changes, and a disconnected connection is
    frozen.
  - An import's identity never changes, its status only moves forward, and
    a finished import is frozen.

## Provenance

A GitHub source snapshot carries, in its immutable metadata:

```json
{ "provenance": { "provider": "github", "repository_id": 1296269, "repository": "octo-org/billing-service",
                  "ref": "main", "commit_sha": "6dcb09b5…", "import_id": "01…", "imported_at": "…" },
  "archive": { "format": "zip", "entries": 2, "directories": 0, "uncompressed_bytes": 38 } }
```

`source_hash` (SHA-256 of the stored bytes) remains authoritative for what
was analyzed. The chain is traceable end to end:

```text
CodeDNA → DNA snapshot → analysis run → source snapshot → GitHub import → repository ID + commit SHA
```

Provenance does not depend on GitHub URLs, so it survives renames, deletions
and disconnection.

## Security model

| Threat | Control |
|---|---|
| Login CSRF / account mix-up | State bound to the user who started it, single use, 10 minutes, hashed at rest; callback needs the session and CSRF token |
| Token theft | Encrypted at rest (APP_KEY); never returned, logged, queued or sent to the browser; installation tokens never stored |
| Over-privilege | App permissions `contents:read` + `metadata:read`; installation tokens narrowed to one repository and those permissions |
| Unauthorized repository access | Every repository and branch is checked as the user; imports re-check access when requested |
| Cross-user access | Owner-only policy (404 for others); composite lineage keys; scoped bindings |
| SSRF | Configured origins only; no client URLs; no automatic redirects; exact-origin allowlist for the archive redirect; no credential sent to the download host |
| Command injection | No shell or process execution anywhere (`NoCommandExecutionTest`); identifiers validated and URL-encoded |
| Hostile archives | Phase 07 inspector and limits unchanged: 50 MiB archive, 200 MiB expanded, 20,000 files, 25 MiB per file, no traversal, no symlinks, CRC and decompressed sizes verified; streamed with a cap; content type checked; commit checked |
| Hostile responses | Bounded bodies, strict field validation, no raw messages passed on |
| Rate limiting | Per-user limits on every GitHub route; GitHub's limits respected and surfaced |

## Rate limits

| Limiter | Routes | Limit (per user) |
|---|---|---|
| `github-authorize` | `POST /github/authorizations`, `POST /github/callback` | 10 / minute |
| `github-read` | installations, repositories, branches | 60 / minute |
| `github-write` | connect, change branch, disconnect, unlink | 20 / minute |
| `github-import` | `POST /projects/{project}/github/imports` | 5 / minute, 30 / hour |

## Configuration

| Variable | Default | Notes |
|---|---|---|
| `GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET` | empty | All or none; empty keeps the integration off (`GITHUB_NOT_CONFIGURED`) |
| `GITHUB_APP_PRIVATE_KEY` / `GITHUB_APP_PRIVATE_KEY_PATH` | empty | PEM (`\n` allowed) or a mounted file; validated at boot; never committed |
| `GITHUB_CALLBACK_URL` | `APP_URL/app/github/callback` | Must match the App |
| `GITHUB_API_URL`, `GITHUB_WEB_URL` | `https://api.github.com`, `https://github.com` | HTTPS required in production |
| `GITHUB_ARCHIVE_ORIGINS` | `https://codeload.github.com` | Comma-separated origins without paths |
| `GITHUB_*_TIMEOUT_SECONDS`, `GITHUB_MAX_RESPONSE_BYTES`, `GITHUB_IMPORT_JOB_TIMEOUT_SECONDS`, `GITHUB_IMPORT_STALE_AFTER_SECONDS` | 5 / 15 / 120, 4 MiB, 180, 900 | Validated as a chain |

## Not included (future phases)

- Automatic analysis on push, webhooks, continuous monitoring and
  notifications.
- Pull request analysis, commit statuses, check runs, PR comments, a review
  bot, and GitHub Actions.
- Organization-wide or team analytics.
- GitLab, Bitbucket and GitHub Enterprise Server. The URLs are configurable,
  but this is untested.
- Revoking the user's token at GitHub on unlink: unlinking deletes it on the
  CodeDNA side only.
