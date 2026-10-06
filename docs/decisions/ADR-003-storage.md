# ADR-003: Source Code Storage

- **Status:** Accepted (amended 2026-10-05, Phase 02: local storage = MinIO;
  amended 2026-10-07, Phase 07: key layout, tests, deletion and retention)
- **Date:** 2026-10-05
- **Related:** [ADR-005](ADR-005-service-communication.md), [Data flow](../architecture/data-flow.md)

## Context

Users provide source code. In the MVP this is a ZIP upload; later it comes
from GitHub or GitLab. That code must be stored long enough to be analyzed,
and possibly re-analyzed when the analyzer improves (ADR-004). Source code is
sensitive. Storing it in PostgreSQL would bloat the database. Storing it on a
local filesystem doesn't work with more than one container.

## Decision

1. **Source archives live in S3-compatible object storage**, accessed only
   through standard S3 API semantics: path-style addressing and SigV4
   pre-signed URLs.

   | Environment | Storage | Endpoint |
   |---|---|---|
   | Local development | **MinIO** (Docker service `minio`) | `http://minio:9000`, region `us-east-1` |
   | Production | **Cloudflare R2** | `https://<account-id>.r2.cloudflarestorage.com`, region `auto` |
   | Automated tests | The local MinIO bucket under a per-test prefix (Phase 07), analyzer-side fixtures | — |

   Only configuration differs between environments (`SOURCE_STORAGE_*`).
   Application code must not use MinIO-specific APIs such as the admin API
   or MinIO SDK extensions. Bucket and credential provisioning is
   infrastructure, not application code: `docker/minio/init.sh` does it
   locally, and the R2 bucket and scoped API token are configured at
   deployment (Phase 25).
2. **The unit of storage is an immutable *source snapshot***: one archive
   (ZIP in the MVP) plus metadata stored in PostgreSQL. The metadata includes
   the object key, SHA-256, size in bytes, origin (upload/provider), and the
   commit SHA when one is known. A snapshot is never modified. New code means
   a new snapshot.
3. **Object keys contain no user-supplied names**, for example
   `snapshots/{snapshot_ulid}.zip`. The original filename is kept only as
   sanitized metadata.
4. **The bucket is private.** No public URLs are ever generated.
5. **Laravel is the only component with storage credentials.** It gives the
   analyzer a **short-lived pre-signed GET URL** (default 15 minutes) for one
   object per analysis request (ADR-005). The analyzer has no storage
   credentials. It only fetches from hosts on its allow-list and verifies the
   size and SHA-256 before extracting.
6. **The analyzer extracts archives only into an ephemeral, per-request
   working directory** and deletes it when the request finishes, whether it
   succeeded or failed.
7. Encryption at rest is provided by the storage provider (R2 encrypts at
   rest by default). Transport uses TLS outside the private network.

## Phase 07 amendments

- **Key layout:** `projects/{project_id}/snapshots/{snapshot_id}/source.zip`
  instead of `snapshots/{snapshot_ulid}.zip`. Still no user-supplied names;
  the project prefix makes a project's objects easy to find for a purge.
- **Original file names are not stored**, not even sanitized: they can be
  sensitive and nothing needs them.
- **Tests use the real MinIO bucket** (under a per-test `phpunit/` prefix
  that is deleted afterwards) instead of a fake disk, so storage integration
  is actually exercised. Failure paths use a mocked disk.
- **No project deletion yet.** Projects are archived (`POST
  /api/v1/projects/{project}/archive`). Deleting a project, and its objects,
  becomes part of the explicit purge workflow, together with account
  deletion. The consequence below is therefore deferred.
- **Retention (open question below):** until that workflow exists,
  snapshots and their objects are kept indefinitely, archived projects
  included.

## Consequences

- Re-analysis is possible as long as a snapshot is retained.
- Pre-signed URLs keep the analyzer credential-free (least privilege). The
  allow-list stops the analyzer from becoming an SSRF primitive.
- Deleting a project must delete its snapshots' objects. This is
  implemented with the purge workflow (not in Phase 07; see the amendments
  above).

## Alternatives considered

- **Sending the archive in the analyzer request body:** simple, but it makes
  internal requests very large, keeps big payloads in memory in the PHP
  worker, and makes retries expensive. Rejected for the MVP. It may be
  reconsidered for very small inputs such as coding challenges.
- **Analyzer with its own read-only bucket credentials:** workable, but it
  widens credential distribution. Rejected in favour of pre-signed URLs.
- **Shared Docker volume:** doesn't carry over to multi-host production.
  Rejected.

## Open questions

- **Retention policy** for source snapshots: keep until the project is
  deleted, or delete automatically after N days and keep only derived
  metrics. This affects re-analysis (ADR-004). Proposed default: keep until
  the user deletes the project, with a later per-user option for
  "analyze and discard". Still open after Phase 07: objects are currently
  kept indefinitely (see the amendments above).
- ~~Local S3 emulator choice~~ **Resolved in Phase 02: MinIO.** Upstream MinIO
  no longer publishes freely pullable container images. The project uses
  Chainguard's maintained build of upstream MinIO, pinned by digest (see
  [infrastructure.md](../architecture/infrastructure.md#minio-image)).
  Pre-signed URL download by the analyzer is verified by `make verify`.
