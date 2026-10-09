# Import security

Phase 28. These rules apply to GitLab and Bitbucket Cloud imports. GitHub
imports share the archive rules through `RepositoryArchives`.

## Threats and controls

| Threat | Control |
|---|---|
| SSRF through a user-supplied URL | There is none. Users send a provider key, a repository ID matching a strict pattern and a branch name. Every host comes from configuration |
| SSRF through a provider response (redirect, pagination link, archive URL) | `ProviderHttp` follows no redirects, except one archive redirect to a configured archive origin. Pagination uses page numbers, never `next` URLs. Provider URLs in responses are never fetched |
| Token sent to a third party | The bearer token is attached only to the provider's own API or web origin. An archive redirect to another origin is fetched without it |
| Token leakage | See [oauth-setup.md](oauth-setup.md#token-handling) |
| Cross-user linking or replay | Hashed single-use state bound to user and provider; one identity per user (see [the flow](oauth-setup.md#the-flow)) |
| Cross-tenant access (IDOR) | Project routes use the project policies: `view` to read, `connectSource` to change. Imports are looked up within their project. Repository listings always come from the provider as the caller |
| Zip slip, absolute paths, symlinks, devices | `ZipArchiveInspector`, the same as uploads: rejected as `SOURCE_ARCHIVE_UNSAFE` |
| Zip bombs and huge repositories | The download is capped at `codedna.sources.limits.archive_bytes` while streaming, whatever `Content-Length` says. Uncompressed size, file count and per-file size are checked before storing |
| Archive of the wrong commit | The archive is requested by its 40-hex commit, never by branch name. When the ZIP comment names a commit (as `git archive` writes it), it must be that commit, or the import fails with `SOURCE_ARCHIVE_INVALID` |
| HTML or error pages stored as source | Only `application/zip`, `application/x-zip-compressed` and `application/octet-stream` are accepted |
| Code execution | None. CodeDNA never checks out, builds or runs repository code. The archive is stored, and the analyzer parses it statically like an upload |
| Duplicate work or double charge | One in-progress import per project (unique index), a conditional claim in the job, and one snapshot per (project, provider, repository, commit). Replays consume nothing |
| Long transactions | No network I/O happens inside a database transaction. The token refresh holds only the account row lock and is bounded by the HTTP timeouts |

## HTTP client

`App\Services\Repositories\ProviderHttp`:

- **Origins:** a URL is accepted only if its scheme, host and port exactly
  match an allowed origin and it has no user info. A suffix host
  (`bitbucket.org.evil.example`), another port, another scheme, a relative
  URL or any unlisted host, including an IP literal, is `RedirectRejected`.
- **Timeouts:** the connect, request and download timeouts come from the
  `codedna.github` settings (`GITHUB_*_TIMEOUT_SECONDS`).
- **Bounded bodies:** JSON bodies are read as streams up to
  `max_response_bytes` (default 4 MiB). Archives stream to a temporary file
  up to the archive limit. Memory use does not grow with the archive size.
- **Retries:** only GET requests are retried, once, after
  `retry_delay_ms`, and only on 502, 503, 504 or a connection error. Token,
  refresh and revocation POSTs are never retried.
- **Error mapping:**

  | Status | Error |
  |---|---|
  | 401 | Unauthorized |
  | 403 | Forbidden |
  | 404 | NotFound |
  | 429 | RateLimited, with `Retry-After` clamped to 1–3600 s |
  | 5xx | Unavailable |
  | Any other unexpected status | InvalidResponse |
  | Timeout | Timeout |

  Logs record the error kind and HTTP status only.
- **Identification:** the `User-Agent` header identifies CodeDNA.

## Storage isolation

A provider snapshot is stored like an upload: in the private `sources` disk
under `projects/{project}/snapshots/{snapshot}/source.zip`. It is never
public and is read by the analyzer only through short-lived internal URLs.
If anything fails after the object is written, the object is deleted
before the import is marked failed or cancelled.

## Configuration

Provider URLs can be changed only by the administrator, through
environment variables, and must be HTTPS in production. Users never supply
a host. See [oauth-setup.md](oauth-setup.md#what-the-validator-enforces).
