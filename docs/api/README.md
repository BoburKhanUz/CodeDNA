# CodeDNA Public API (v1)

The public product API is served by Laravel under `/api/v1`. The **internal**
analyzer API is separate and never public; see
[internal-analyzer-contract.md](internal-analyzer-contract.md).

**Status:** Phase 03/04. The endpoints below are implemented and tested, and
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
| `GET` | `/api/v1/me` | authenticated | 200 | Current user |

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
unless they are added to the resource.

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
- `register`, `login` and `logout` **require** a session. A request without
  one gets `400 BAD_REQUEST`.

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

- `Content-Type: application/json`. File uploads (later) use
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

`204 No Content` responses have no body. Collections (in later phases) add
page-based pagination: `?page=2&per_page=25`, with `per_page` at most 100,
plus `meta` and `links`.

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
  messages.
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
| 405 | `METHOD_NOT_ALLOWED` | Wrong HTTP method for the route |
| 413 | `PAYLOAD_TOO_LARGE` | Body exceeds the limit |
| 419 | `CSRF_TOKEN_MISMATCH` | Missing or stale `X-XSRF-TOKEN` |
| 422 | `VALIDATION_FAILED` | Input failed validation (`details.fields`) |
| 422 | `INVALID_CREDENTIALS` | Login with a wrong email/password combination |
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

Every attempt counts, successful or not. When a limit is exceeded the
response is `429 RATE_LIMITED` with `Retry-After`. Throttled routes also
return `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Limits are
configured in `config/codedna.php`.

## Planned endpoints (not implemented)

| Method | Path | Phase |
|---|---|---|
| `GET`, `POST` | `/api/v1/projects` | 07 |
| `GET`, `PATCH`, `DELETE` | `/api/v1/projects/{project}` | 07 |
| `GET`, `POST` | `/api/v1/projects/{project}/repositories` | 07 |
| `GET` | `/api/v1/repositories/{repository}` | 07 |
| `POST` | `/api/v1/repositories/{repository}/snapshots` (ZIP upload) | 07 |
| `POST` | `/api/v1/analyses` | 10 |
| `GET` | `/api/v1/analyses/{analysis}` | 10 |
| `GET` | `/api/v1/analyses/{analysis}/status` | 10 |
| `POST` | `/api/v1/analyses/{analysis}/runs` (re-run) | 10 |
| `GET` | `/api/v1/dna` | 11/12 |
| `GET` | `/api/v1/dna/history` | 12 |

Analysis `status` values: `pending`, `queued`, `processing`, `completed`,
`failed` ([data-flow.md](../architecture/data-flow.md)).
