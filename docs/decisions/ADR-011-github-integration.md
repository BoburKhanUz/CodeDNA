# ADR-011: GitHub Integration — A GitHub App as a Read-Only Source Provider

- **Status:** Accepted
- **Date:** 2026-10-16
- **Related:**
  - [ADR-003](ADR-003-storage.md)
  - [ADR-005](ADR-005-service-communication.md)
  - [ADR-006](ADR-006-authentication.md)
  - [GitHub integration v1](../architecture/github-integration-v1.md)

## Context

Until Phase 19, source code reaches CodeDNA only as uploaded ZIP archives
(Phase 07). Developers keep their code on GitHub and want to analyze it from
there. Connecting a third-party code host adds risks that uploads do not
have:

- **Long-lived credentials** that grant access to private code.
- **Over-broad permissions.** OAuth App scopes such as `repo` grant write
  access to every repository the user can reach.
- **Untrusted inputs:** repository names, branch names, installation IDs
  and download URLs.
- **Server-side requests** to URLs influenced by others (SSRF).
- **A second pipeline** that would weaken the immutable-snapshot model of
  ADR-003 and ADR-004.

## Decision

### 1. GitHub is a source provider; SourceSnapshot stays the source of truth

- **A snapshot like an upload.** An import produces an ordinary immutable
  source snapshot: `source_type = REPOSITORY`, with GitHub provenance in its
  metadata.
- **The same checks and storage.** It goes through the same
  `ZipArchiveInspector`, limits, hashing, private storage and
  `RecordSourceSnapshot` as an upload.
- **The same analysis path.** Analysis is still started by
  `POST /analyses`, and nothing is analyzed automatically.
- **Independent of GitHub afterwards.** After an import, nothing depends on
  GitHub: disconnecting, uninstalling, deleting the repository or an expired
  token never touches history.

### 2. A GitHub App, with read-only Contents and Metadata

The App is the only GitHub model that lets CodeDNA ask for exactly these
two read-only permissions, on exactly the repositories the owner chose.

- **Installation tokens:**
  - minted per import (one hour);
  - narrowed further to one repository and those two permissions;
  - never stored.
- **The user's App authorization** (an OAuth flow during installation)
  identifies the user. Every repository and branch is verified **as the
  user**, so an organization installation never exposes repositories the
  user cannot see.

Identities stay separate:

- CodeDNA user ↔ GitHub user (`github_accounts`);
- App ↔ installation (on GitHub);
- project ↔ repository (`github_connections`).

### 3. Encrypted user tokens; no client-held credentials

- **Encrypted at rest.** User access and refresh tokens are encrypted with
  Laravel's encrypter (`APP_KEY`). No custom cryptography is used.
- **Server-side only.** No token is ever returned to the browser, logged,
  put in a URL or queued. The import job carries only the import ID.

### 4. Server-side, single-use OAuth state

- **The state:** 32 random bytes, stored as SHA-256, bound to the user, valid
  for 10 minutes, consumed atomically once.
- **The callback:** a frontend page that POSTs the code and state with the
  session and CSRF token. The API cannot be the redirect target: a
  navigation from github.com is not a stateful Sanctum request.
- **Ignored parameters:** callback parameters other than the code and state
  are ignored.

### 5. Official API only; no git, no execution; strict network rules

- **The archive:** `GET /repos/{o}/{r}/zipball/{sha}` for the exact commit
  the server resolved.
- **The redirect:** it is never followed automatically. It is fetched only
  when its origin is in a configured allowlist (`codeload.github.com`),
  without sending any credential.
- **Configured endpoints only:** API and web URLs come from configuration,
  never from a request.
- **Bounded:** timeouts, bodies and retries are all bounded.

There is no `git clone` and no package manager, build or script.

### 6. No webhooks in Phase 19

Webhooks would add automatic analysis on push, which is out of scope.
Uninstallation and revoked access surface as safe failures on the next
request. The model (stable repository and installation IDs, a
provider-neutral import lifecycle) leaves room for a later webhook phase.

## Consequences

### Positive

- CodeDNA can never write to a repository, and only sees repositories the
  owner granted and the user can see.
- A leak of the database alone reveals no usable token without `APP_KEY`.
- Imports are reproducible from immutable snapshots; GitHub is out of the
  analysis path.
- The same commit is never stored twice, whatever the branch or number of
  requests.

### Negative

- **Setup.** Operators must register a GitHub App (ID, slug, client ID and
  secret, private key) and keep its private key in a secret store.
- **Two credentials.** Users authorize the App, and someone with rights on
  the account or organization installs it.
- **No live sync.** Imports are manual; a new commit is not imported until
  someone asks.
- **Reinstallation.** If the App is reinstalled (a new installation ID), the
  project must be reconnected.
- **Symbolic links.** Repositories containing symbolic links are rejected
  (`SOURCE_ARCHIVE_UNSAFE`), as uploads are.

## Alternatives considered

| Alternative | Why it was rejected |
|---|---|
| OAuth App with the `repo` scope | Read and write access to every repository the user can reach; no per-repository or read-only scope for private code |
| Personal access tokens pasted by users | Long-lived, often over-scoped, stored secrets CodeDNA would have to manage; poor UX and revocation |
| `git clone` in a worker | Runs a complex parser on hostile input, needs network access to arbitrary remotes, supports submodules, LFS and hooks, and makes SSRF and command injection hard to rule out |
| Following GitHub's archive redirect automatically | Lets a response decide which host is contacted; a compromised or misconfigured redirect could reach internal services |
| Storing installation tokens | Unnecessary: one is minted per import from the private key |
| A separate GitHub snapshot format or pipeline | Duplicates validation and storage, and breaks the single immutable-snapshot model |
| Automatic import and analysis on every push (webhooks) | Out of Phase 19 scope; needs signature verification, replay protection and cost controls designed on their own |
