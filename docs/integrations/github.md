# GitHub

GitHub was the first repository provider (Phase 19). Its design is in
[github-integration-v1.md](../architecture/github-integration-v1.md) and
[ADR-011](../decisions/ADR-011-github-integration.md); its API is in the
[API reference](../api/README.md#github-integration).

## What changed in Phase 28

- **Shared import core.** `RunGitHubImport` now uses
  `App\Actions\Sources\RepositoryArchives` (inspect, store, record,
  discard), which GitLab and Bitbucket use too. Behavior, failure codes and
  storage keys are unchanged, and every Phase 19 test passes unmodified.
- **One source per project.** Connecting GitHub to a project that has an
  active GitLab or Bitbucket connection answers `409
  SOURCE_ALREADY_CONNECTED`, and the reverse is also true.
- **Shared quota.** `GITHUB_IMPORTS` counts imports from every provider;
  its label is now "Repository imports". See
  [provider-architecture.md](provider-architecture.md#quota-decision).

## Why GitHub is not an OAuth adapter

GitLab and Bitbucket use a per-user OAuth token for everything. GitHub uses
a GitHub App: the user's OAuth token proves who they are and which
installations they can see, and a short-lived **installation token** reads
the repository. That second check, that the CodeDNA App is installed on the
repository, has no counterpart in the generic contract, so GitHub keeps its
own adapter (`GitHubApi`/`GitHubHttp`) and tables.
