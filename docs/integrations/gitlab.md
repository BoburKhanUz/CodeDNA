# GitLab

Phase 28. Import a GitLab repository's branch into a CodeDNA project.
Works with **GitLab.com** or **one self-managed GitLab instance** per
CodeDNA installation, chosen by the administrator with `GITLAB_BASE_URL`.
Users never enter a URL.

Setup: [oauth-setup.md](oauth-setup.md#gitlab). Architecture:
[provider-architecture.md](provider-architecture.md). API:
[API reference](../api/README.md#gitlab-and-bitbucket-cloud).

## Capabilities

| Capability | How |
|---|---|
| Connect an account | OAuth 2.0 authorization code with scope **`read_api`** only (`/oauth/authorize`, `/oauth/token`) |
| Token lifetime | GitLab access tokens expire (about 2 hours). CodeDNA refreshes them within a minute of expiry, under a row lock, because GitLab rotates refresh tokens |
| List repositories | `GET /api/v4/projects?membership=true&min_access_level=20`: projects the user is a member of with at least Reporter access (the level that can read code), most recently active first, paginated with `X-Next-Page` |
| Private repositories | Yes. `private` and `internal` visibility are both shown as private |
| Repository ID | GitLab's numeric project ID (`^[1-9][0-9]{0,18}$`) |
| Branches | `GET /api/v4/projects/{id}/repository/branches` (paginated) and `…/branches/{name}` (the name is URL-encoded as one path segment) |
| Archive | `GET /api/v4/projects/{id}/repository/archive.zip?sha={40-hex}`, streamed with the user's token and checked against the commit |
| Disconnect | `POST /oauth/revoke` for the access token (best effort); CodeDNA's copy is deleted whatever GitLab answers |
| Self-managed | `GITLAB_BASE_URL=https://git.example.com` (a path prefix such as `/gitlab` is allowed). API, OAuth and archive all use this one origin |

## Not supported

- A user-supplied GitLab URL, or more than one GitLab instance per
  installation.
- Group-wide imports, merge request or pipeline data, and webhooks or
  automatic imports. An import is always a user action.
- Personal or project access tokens: only OAuth.
- Submodules and Git LFS objects: GitLab's archive does not include their
  content, so the snapshot contains what the archive contains.

## Behavior notes

- **Empty repository.** A project with no default branch cannot be
  connected (`PROVIDER_BRANCH_NOT_FOUND`).
- **Access lost.** If the user loses access, or the project is deleted or
  moved, the next connect, branch or import call answers
  `PROVIDER_REPOSITORY_NOT_FOUND`. A running import fails with the same
  code and its quota unit is refunded.
- **Branch deleted.** The import fails with `PROVIDER_BRANCH_NOT_FOUND`.
  CodeDNA never switches branches on its own.
- **Rate limits.** A `429` becomes `PROVIDER_RATE_LIMITED` with
  `Retry-After` (clamped to 1–3600 s), from GitLab's `Retry-After` or
  `RateLimit-Reset`.
