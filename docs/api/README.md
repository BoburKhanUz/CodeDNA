# CodeDNA Public API (v1)

The public product API is served by Laravel under `/api/v1`. The **internal**
analyzer API is separate and never public; see
[internal-analyzer-contract.md](internal-analyzer-contract.md).

**Status:** Phase 06. The endpoints below are implemented and tested, and
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
| `PATCH` | `/api/v1/auth/password` | authenticated, browser session | 204 | Change the password (ends the session) |
| `GET` | `/api/v1/me` | authenticated | 200 | Current user |
| `GET` | `/api/v1/profile` | authenticated | 200 | The caller's developer profile |
| `PATCH` | `/api/v1/profile` | authenticated | 200 | Update the caller's developer profile |

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
unless they are added to the resource. `/me` is the identity only; the
developer profile has its own endpoint.

### `GET /api/v1/profile`

Returns the authenticated developer's profile. There is no profile ID in the
URL: a caller can only ever read and change their own profile, so another
user's profile cannot be addressed.

```json
{
  "data": {
    "id": "01k6m2y5a7j1x9v3q8n4r2t6xa",
    "type": "developer_profile",
    "display_name": "Ada",
    "bio": "Backend developer.",
    "avatar_url": null,
    "timezone": "Asia/Tashkent",
    "locale": "uz",
    "country_code": "UZ",
    "city": "Tashkent",
    "job_title": "Senior Engineer",
    "company": null,
    "website_url": "https://ada.example.com",
    "github_username": "ada-lovelace",
    "linkedin_url": null,
    "preferred_language": "php",
    "updated_at": "2026-10-06T12:00:00Z"
  }
}
```

Profiles are serialized by `DeveloperProfileResource` with an explicit field
list. The owner ID and the internal `metadata` column are never returned.
Every user has a profile: registration creates it. A user created some other
way gets the default profile on first access (still `200`).

### `PATCH /api/v1/profile`

A partial update: send only the fields to change. `null` (or `""`) clears an
optional field. Any other field (`id`, `user_id`, `metadata`, ...) is
ignored. The response is the updated profile (`200`).

| Field | Rules |
|---|---|
| `display_name`, `city`, `job_title`, `company` | optional, string, max 100 |
| `bio` | optional, string, max 1000 |
| `avatar_url`, `website_url`, `linkedin_url` | optional, absolute `https://` URL with a host, max 2048, no `user:password@`, no whitespace |
| `timezone` | cannot be cleared; a canonical IANA time zone (`UTC`, `Asia/Tashkent`, `Europe/Moscow`). Offsets such as `UTC+5`, legacy aliases and wrong case are rejected |
| `locale` | cannot be cleared; one of `en`, `uz`, `ru` |
| `country_code` | optional, two letters; stored uppercase (`uz` → `UZ`) |
| `github_username` | optional, GitHub's format: 1–39 letters, digits and single hyphens, not at either end. A leading `@` is removed |
| `preferred_language` | optional, one of `c`, `cpp`, `csharp`, `dart`, `elixir`, `go`, `java`, `javascript`, `kotlin`, `php`, `python`, `ruby`, `rust`, `scala`, `swift`, `typescript` |

`timezone` and `locale` are presentation preferences. API timestamps stay
UTC, and the UI is not translated yet.

### `PATCH /api/v1/auth/password`

```json
{ "current_password": "…", "password": "…", "password_confirmation": "…" }
```

| Field | Rules |
|---|---|
| `current_password` | required, must match the account's password |
| `password` | required, the registration policy (8–72 characters), must match `password_confirmation`, must differ from `current_password` |

On success the response is `204` with no body. The password is re-hashed
with bcrypt, the remember-me token is rotated, and **the current session is
invalidated** (as on logout), so the client must sign in again. Every other
session of the user is rejected on its next request (`401`), because
Sanctum's `AuthenticateSession` middleware compares the password hash each
session was created with. A wrong current password returns `422
VALIDATION_FAILED` with `details.fields.current_password`. Password values
never appear in responses or logs; a log line records only that the user's
password changed.

### Account deletion and email verification

There is no account deletion endpoint. Users, profiles and project history
use `RESTRICT` foreign keys, so deleting an account needs a deliberate purge
workflow, planned for a later phase. Email verification is not enforced yet
(`email_verified_at` is returned but nothing requires it); see
[backend.md](../architecture/backend.md#authentication-and-authorization).

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
- `register`, `login`, `logout` and `auth/password` **require** a session.
  A request without one gets `400 BAD_REQUEST`.

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
| `profile-update` | `PATCH /profile` | 30 / minute | user ID |
| `password-change` | `PATCH /auth/password` | 5 / minute **and** 20 / hour | user ID |

Every attempt counts, successful or not. When a limit is exceeded the
response is `429 RATE_LIMITED` with `Retry-After`. Throttled routes also
return `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Limits are
configured in `config/codedna.php`.

## Planned endpoints (not implemented)

| Method | Path | Phase |
|---|---|---|
| `GET`, `POST` | `/api/v1/projects` | 07 |
| `GET`, `PATCH`, `DELETE` | `/api/v1/projects/{project}` | 07 |
| `GET`, `POST` | `/api/v1/projects/{project}/snapshots` (ZIP upload) | 07 |
| `POST` | `/api/v1/projects/{project}/snapshots/{snapshot}/analysis-runs` (start or re-run) | 10 |
| `GET` | `/api/v1/analysis-runs/{run}` (status and result) | 10 |
| `GET` | `/api/v1/dna` | 11/12 |
| `GET` | `/api/v1/dna/history` | 12 |

Paths are indicative and are finalized in their phase. They follow the
domain model in [data-model.md](../architecture/data-model.md): projects
own snapshots, snapshots have analysis runs, and runs produce DNA snapshots.
Analysis run `status` values: `QUEUED`, `RUNNING`, `SUCCEEDED`, `FAILED`,
`CANCELLED` ([data-flow.md](../architecture/data-flow.md)).
