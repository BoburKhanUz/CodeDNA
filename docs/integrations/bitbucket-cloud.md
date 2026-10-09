# Bitbucket Cloud

Phase 28. Import a Bitbucket Cloud (bitbucket.org) repository's branch into
a CodeDNA project. **Bitbucket Data Center / Server is not supported.**

Setup: [oauth-setup.md](oauth-setup.md#bitbucket-cloud). Architecture:
[provider-architecture.md](provider-architecture.md). API:
[API reference](../api/README.md#gitlab-and-bitbucket-cloud).

## Capabilities

| Capability | How |
|---|---|
| Connect an account | OAuth 2.0 authorization code against an OAuth consumer (`/site/oauth2/authorize`, `/site/oauth2/access_token`, client credentials sent with HTTP Basic). Permissions are set on the consumer: **Account: Read** and **Repositories: Read** |
| Token lifetime | Access tokens expire (about 2 hours); CodeDNA refreshes them under a row lock |
| List repositories | `GET /2.0/repositories?role=member`: repositories the user is a member of, paginated with Bitbucket's `next` link |
| Private repositories | Yes (`is_private`) |
| Repository ID | `{workspace-uuid}/{repository-uuid}`, both in braces, so a renamed workspace or repository keeps its connection |
| Branches | `GET /2.0/repositories/{workspace}/{repo}/refs/branches` (paginated) and `…/refs/branches/{name}` |
| Archive | `https://bitbucket.org/{workspace}/{slug}/get/{40-hex}.zip` with the user's token. One redirect is followed, and only to an origin in `BITBUCKET_ARCHIVE_ORIGINS`; a redirect to another origin is fetched **without** the token |
| Disconnect | Bitbucket Cloud has **no token revocation endpoint**. CodeDNA deletes its copy of the tokens and answers `revocation: "NOT_SUPPORTED"`; the user can revoke the consumer's access in Bitbucket (*Personal settings → OAuth*) |

## Not supported

- Bitbucket Data Center or Server, or any host other than bitbucket.org.
- App passwords, API tokens or workspace access tokens: only OAuth.
- Webhooks or automatic imports.
- Submodules and Git LFS content (not in the archive).

## Behavior notes

- **Workspace or repository renamed.** The connection keeps working,
  because the IDs are UUIDs. The stored `full_name` is refreshed from
  Bitbucket on the next connect, branch change or import.
- **Access lost.** Losing access to the repository answers
  `PROVIDER_REPOSITORY_NOT_FOUND`.
- **Rate limits.** Bitbucket's hourly limits answer `429`, which becomes
  `PROVIDER_RATE_LIMITED`.
- **Archive host.** If Bitbucket starts redirecting archive downloads to a
  new host, imports fail with `PROVIDER_IMPORT_FAILED` until an
  administrator adds that origin to `BITBUCKET_ARCHIVE_ORIGINS` (see
  [troubleshooting.md](troubleshooting.md)).
