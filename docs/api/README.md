# CodeDNA Public API — Conventions (v1)

This document defines the conventions for the **public product API** served
by Laravel. The **internal** analyzer API is separate and never public; see
[internal-analyzer-contract.md](internal-analyzer-contract.md).

Status: **conventions accepted; no endpoints are implemented yet.** Endpoints
are added phase by phase. Each endpoint is documented here, and in the
OpenAPI description in `packages/api-contracts/`, when it is implemented.

## Base path and versioning

- All endpoints are served under **`/api/v1/`**.
- Within `v1`, changes are additive only: new endpoints, new optional request
  fields, new response fields. Clients must ignore unknown response fields.
- Breaking changes require `/api/v2/`. `v1` keeps working through a
  documented deprecation window, announced with the `Deprecation` and
  `Sunset` response headers.
- Laravel's built-in health route `/up` sits outside the versioned API.

## Authentication

- Browser (Next.js): Sanctum SPA session cookie with CSRF protection. See
  [ADR-006](../decisions/ADR-006-authentication.md).
  1. `GET /sanctum/csrf-cookie`
  2. Send the `X-XSRF-TOKEN` header on state-changing requests.
- Future external clients: `Authorization: Bearer <personal access token>`,
  on the same endpoints, through the same `auth:sanctum` guard.
- Unauthenticated → `401`. Authenticated but not allowed → `403`. A resource
  that the caller may not know exists → `404` (no existence leaks).

## Requests

- `Content-Type: application/json`, except file uploads, which use
  `multipart/form-data`.
- Clients send `Accept: application/json`.
- Field names are `snake_case`.
- IDs are **ULIDs** (26-character strings): sortable and not enumerable.
  Database auto-increment IDs are never exposed.
- Timestamps are ISO 8601 in UTC with a `Z` suffix, e.g.
  `2026-10-05T12:00:00Z`.

## Responses

Single resource:

```json
{ "data": { "id": "01JBX7T3K4Q9Z8V6M2N5P1R0SA", "type": "project", "name": "..." } }
```

Collections use page-based pagination (`?page=2&per_page=25`; `per_page` is
at most 100):

```json
{
  "data": [ { "id": "...", "type": "project" } ],
  "meta": { "current_page": 2, "per_page": 25, "total": 51, "last_page": 3 },
  "links": { "first": "...", "prev": "...", "next": "...", "last": "..." }
}
```

## Errors

Every error uses one envelope. It mirrors the internal contract's shape, so
there is a single error vocabulary across the system.

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "request_id": "6f1c2a4e-1d3b-4c55-9a7e-2b8f0c9d1e23",
    "details": { "fields": { "name": ["The name field is required."] } }
  }
}
```

| HTTP | `code` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 409 | `conflict` |
| 413 | `payload_too_large` |
| 419 | `csrf_token_mismatch` |
| 422 | `validation_failed` |
| 429 | `rate_limited` (with a `Retry-After` header) |
| 500 | `server_error` (no internal details, ever) |
| 503 | `service_unavailable` |

Domain-specific codes (for example `analysis_not_retryable`) are added per
endpoint and documented with it.

## Request IDs

Every response carries `X-Request-ID`. If the client sends a valid UUID in
`X-Request-ID`, that value is used. Otherwise one is generated. The same ID
appears in error bodies and logs, and is propagated to the analyzer for
analysis jobs.

## Rate limiting

Every route is rate limited. Authentication routes have stricter per-IP and
per-identifier limits. Analysis creation is limited per user, because it
drives expensive work. The exact limits are set when each endpoint is
implemented.

## Planned MVP endpoints

These are **planned and not implemented**. Their exact shapes are finalized
in the phase listed.

| Method | Path | Phase |
|---|---|---|
| `POST` | `/api/v1/auth/register` | 06 |
| `POST` | `/api/v1/auth/login` | 06 |
| `POST` | `/api/v1/auth/logout` | 06 |
| `GET` | `/api/v1/me` | 06 |
| `GET`, `POST` | `/api/v1/projects` | 07 |
| `GET`, `PATCH`, `DELETE` | `/api/v1/projects/{project}` | 07 |
| `GET`, `POST` | `/api/v1/projects/{project}/repositories` | 07 |
| `GET` | `/api/v1/repositories/{repository}` | 07 |
| `POST` | `/api/v1/repositories/{repository}/snapshots` (ZIP upload) | 07 |
| `POST` | `/api/v1/analyses` (`{ "snapshot_id": "..." }`) | 10 |
| `GET` | `/api/v1/analyses/{analysis}` | 10 |
| `GET` | `/api/v1/analyses/{analysis}/status` | 10 |
| `POST` | `/api/v1/analyses/{analysis}/runs` (re-run) | 10 |
| `GET` | `/api/v1/dna` | 11/12 |
| `GET` | `/api/v1/dna/history` | 12 |

Analysis `status` values: `pending`, `queued`, `processing`, `completed`,
`failed`. See [data-flow.md](../architecture/data-flow.md).
