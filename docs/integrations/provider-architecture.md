# Repository provider architecture

Phase 28. CodeDNA imports source from three hosts: **GitHub**, **GitLab**
(GitLab.com or one self-managed instance) and **Bitbucket Cloud**. They are
sources only. An import becomes an ordinary immutable source snapshot
(`source_type: "REPOSITORY"`) that is analyzed through `POST /analyses`,
exactly like an upload. No provider adds an analyzer, a score or a code
path into the analysis pipeline.

See also: [GitHub](github.md), [GitLab](gitlab.md),
[Bitbucket Cloud](bitbucket-cloud.md), [OAuth setup](oauth-setup.md),
[Import security](import-security.md), [Troubleshooting](troubleshooting.md)
and the [API reference](../api/README.md#gitlab-and-bitbucket-cloud).

## Two integration models, one import core

| | GitHub | GitLab, Bitbucket Cloud |
|---|---|---|
| Authorization | GitHub App: user OAuth plus installation tokens | OAuth 2.0 authorization code, per user |
| Code | `App\Services\GitHub\*`, `App\Actions\GitHub\*` (Phase 19) | `App\Services\Repositories\*`, `App\Actions\Repositories\*` |
| Tables | `github_*` | `repository_provider_*` (one `provider` column) |
| API | `/api/v1/github`, `/projects/{p}/github` | `/api/v1/repository-providers/{provider}`, `/projects/{p}/repository-provider` |
| Import core | `App\Actions\Sources\RepositoryArchives` | same |
| Quota | `GITHUB_IMPORTS` | same counter |
| Queue | `github` queue, `codedna.github` timeouts | same |

GitHub keeps its own adapter because its trust model differs: a repository
is reachable through an *installation* of the CodeDNA App, with
short-lived installation tokens. Forcing it behind the OAuth contract would
lose the installation check. Everything after "we have a ZIP of a verified
commit" is shared.

```text
 browser ──► controller ──► action ──► RepositoryProvider (GitLab | Bitbucket) ──► ProviderHttp ──► provider
                               │
                               └──► RepositoryArchives: inspect ─► store ─► record snapshot
                                    (also used by RunGitHubImport)
```

## The adapter contract

`App\Services\Repositories\RepositoryProvider`. Each adapter
(`GitLabProvider`, `BitbucketProvider`) implements:

| Method | Purpose |
|---|---|
| `authorizationUrl(state)` | The provider's authorize URL for the configured client and callback |
| `exchangeCode(code)` / `refresh(token)` | Token endpoint calls; the result is validated by `ProviderTokens::fromApi` |
| `revoke(token)` | Best-effort revocation; `false` when the provider has no revocation endpoint |
| `identity(token)` | The provider user ID and username, validated |
| `isRepositoryId(id)` | The one accepted repository ID shape (checked before any request) |
| `repositories(token, page, perPage)` | One page of repositories the user can read, and `has_more` |
| `repository(token, id)` | Fresh, validated metadata for one repository |
| `branches(...)` / `branchHead(...)` | Branch names; the commit SHA a branch points at |
| `downloadArchive(token, repo, sha, dest, max)` | Streams the ZIP of exactly that commit to a local file |

Adapters build every URL themselves from configuration and validated IDs.
Nothing from a request or from a provider response becomes a URL host.
Provider responses are treated as untrusted. A response that does not match
the expected shape (for example a non-numeric ID, a path with `..`, a
missing UUID or a commit that is not 40 hex characters) is
`ProviderError::InvalidResponse` and never stored.

`ProviderHttp` is the only HTTP client the adapters use; see
[import-security.md](import-security.md#http-client). Errors are a closed
enum (`ProviderError`) mapped by `ProviderErrors` to API codes
(`PROVIDER_*`) or import failure codes. Only the error kind and HTTP status
are logged.

## Data model

Migration `2026_10_20_000001_create_repository_provider_tables.php` (new
tables only; it has a `down()`):

- **`repository_provider_accounts`**: one per (user, provider). The
  provider user ID is unique per provider, so one provider identity links to
  one CodeDNA user. `access_token` and `refresh_token` use Laravel's
  `encrypted` cast (APP_KEY), are hidden from serialization and are never
  returned by the API.
- **`repository_provider_oauth_states`**: the SHA-256 hash of the state,
  user, provider, optional project and expiry (at most one hour). It records
  when the state is consumed.
- **`repository_provider_connections`**: the project, provider, repository
  ID and verified metadata, branch, `connected_by` and status. A partial
  unique index allows one `ACTIVE` connection per project. A database
  trigger freezes a disconnected row.
- **`repository_provider_imports`**: the project, connection, provider,
  `requested_by`, ref, commit, status and failure code. Two partial unique
  indexes cover one in-progress import per project and one created snapshot
  per (project, provider, repository, commit). A trigger makes terminal rows
  immutable.

## Connection

A project has **one repository source**: a GitHub connection or one
GitLab/Bitbucket connection, never both. `ConnectProviderRepository` and
`ConnectGitHubRepository` each check the other under the project row lock
and answer `409 SOURCE_ALREADY_CONNECTED`.

Connecting needs the `connectSource` ability (the owner, or an owner or
admin of a team project) and an active project. The repository ID must
match the adapter's shape. The repository is then read from the provider
**as the requesting user**, so a repository they cannot read is
`PROVIDER_REPOSITORY_NOT_FOUND`. The branch is the default branch unless
another is given and verified.

## Import pipeline

1. `POST …/repository-provider/imports` (empty body). `RequestProviderImport`
   does the following:
   - checks the project and the `GITHUB_INTEGRATION` entitlement;
   - re-reads the repository with the **requester's** token;
   - then, under the project lock, either returns the import already in
     progress (`200`, `Idempotent-Replayed`) or creates one and consumes one
     `GITHUB_IMPORTS` unit (`202`).

   The job is dispatched after commit, and its payload is the import ID
   only.
2. `ImportProviderSource`, on the `github` queue with one try, calls
   `RunProviderImport`:
   - It **claims** the import with a conditional update, so a duplicate
     delivery does nothing.
   - It obtains the requester's token, refreshing it under the account row
     lock if needed.
   - It resolves the branch to a commit **now**, not when the import was
     requested.
   - If this project already has a snapshot of that commit from that
     repository, it reuses it (`created_snapshot: false`) without
     downloading.
   - Otherwise it streams the archive to a temporary file, outside any
     database transaction.
   - `RepositoryArchives::inspect` applies the upload limits and refuses
     an archive whose ZIP comment names another commit.
   - It stores the archive privately under
     `projects/{project}/snapshots/{snapshot}/source.zip`.
   - In one short transaction it re-checks that the connection is still
     active and the project still active, then records the snapshot.
     Otherwise it deletes the stored object and records `CANCELLED` or
     `PROJECT_ARCHIVED`.
3. A `FAILED` import refunds its quota unit. A disconnect cancels a queued
   import, and a running one records nothing.

## Quota decision

Imports from every provider consume the existing **`GITHUB_IMPORTS`**
counter, now labeled "Repository imports". The `GITHUB_INTEGRATION`
feature, labeled "Repository integrations", gates all three providers. The
keys are unchanged, so stored counters, plan rows and API contracts stay
valid, and FREE (20/month) and PRO (300/month) keep their limits. A user
cannot double their allowance by switching provider. No plan gains or loses
a feature, and nothing grants Enterprise behavior. See
[entitlements-and-quotas.md](../billing/entitlements-and-quotas.md).
