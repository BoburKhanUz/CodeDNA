# ADR-006: Authentication — Laravel Sanctum (SPA Cookies, Tokens Later)

- **Status:** Accepted
- **Date:** 2026-10-05
- **Related:** [API conventions](../api/README.md), [Backend architecture](../architecture/backend.md)

## Context

The Next.js frontend is the first API client. Later, external clients (CLI,
CI integrations, API customers) will need non-browser authentication.
Authentication tokens must never be readable by JavaScript or stored in
`localStorage` or `sessionStorage`.

## Decision

### 1. Browser: Sanctum SPA (stateful) authentication

- The browser authenticates with Laravel's **session cookie**, which is
  `HttpOnly`, `Secure` in production, and `SameSite=Lax`.
- No authentication token is ever exposed to frontend JavaScript or stored in
  `localStorage`, `sessionStorage`, or a non-`HttpOnly` cookie.
- Login is `POST /api/v1/auth/login`. The session is regenerated on login and
  invalidated on logout (`POST /api/v1/auth/logout`). Registration logs the
  user in.
- Login and registration are rate limited per IP and per identifier
  (email).

### 2. CSRF handling

1. Before the first state-changing request, the client calls
   `GET /sanctum/csrf-cookie`. Laravel sets an `XSRF-TOKEN` cookie, which
   JavaScript can read **by design**: it is a CSRF token, not a credential.
2. The client sends its value in the `X-XSRF-TOKEN` header on every `POST`,
   `PUT`, `PATCH` and `DELETE` request.
3. Laravel validates it. `SameSite=Lax` gives a second layer of defense.
4. All API requests also send `Accept: application/json` and
   `X-Requested-With: XMLHttpRequest`.

### 3. Domain topology

**Preferred, in every environment: a single origin.** Nginx serves the
frontend and the API under one host. `/api/*` and `/sanctum/*` go to Laravel,
and everything else goes to Next.js. The browser never makes cross-origin
API calls, so **CORS is not used** in this topology.

| Environment | Browser origin | Routing |
|---|---|---|
| Local (Docker) | `http://localhost:8080` | Nginx → Next.js / Laravel |
| Production | `https://app.<your-domain>` | Nginx → Next.js / Laravel |

**Supported fallback: split origins on the same site** (for example
`app.<domain>` and `api.<domain>`, or `localhost:3000` and `localhost:8000`
when running natively without Docker):

- `SANCTUM_STATEFUL_DOMAINS` lists the frontend host(s) with port, e.g.
  `localhost:3000`.
- CORS is enabled only for the `api/*` and `sanctum/csrf-cookie` paths. The
  allowed origin is `FRONTEND_URL` only (no wildcard), and
  `supports_credentials` is `true`.
- `SESSION_DOMAIN` is set to the parent domain (e.g. `.<domain>`) only when
  the hosts differ. Otherwise it is left unset, giving a host-only cookie.
- The two origins must be **same-site** (same registrable domain). Cross-site
  setups would need `SameSite=None` and are not supported.

### 4. Cookie and session settings

| Setting | Local | Production |
|---|---|---|
| `SESSION_DRIVER` | `redis` | `redis` |
| `SESSION_SECURE_COOKIE` | `false` (HTTP on localhost) | `true` |
| `SESSION_SAME_SITE` | `lax` | `lax` |
| `SESSION_HTTP_ONLY` | `true` | `true` |
| `SESSION_DOMAIN` | unset (host-only) | unset for single origin; parent domain only for split origins |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:8080,localhost:3000` | `app.<your-domain>` |

Production also requires HTTPS everywhere, HSTS at the edge, and the trusted
proxy configured so Laravel sees the original scheme.

### 5. Next.js server-side rendering

- Server components and route handlers that need user data call Laravel
  **server-to-server**. They forward the incoming request's `Cookie` header
  and set `Origin`/`Referer` to the public origin, so Sanctum treats the call
  as stateful. The internal base URL comes from `BACKEND_INTERNAL_URL`. It is
  never exposed to the browser.
- State-changing operations are sent from the browser with the XSRF header,
  as described above. If a server action has to mutate data, it must forward
  the user's cookies and XSRF token. Server-side code never holds long-lived
  credentials of its own.

### 6. Future external clients: Sanctum personal access tokens

- The same `auth:sanctum` guard accepts `Authorization: Bearer <token>`.
  Adding token clients therefore needs **no redesign**: route groups,
  policies and controllers stay the same.
- Tokens will be issued from account settings in a later phase. They are
  scoped with Sanctum abilities, can be revoked, have optional expiry, and
  are shown to the user once. Only their hash is stored.
- Browser code never uses personal access tokens.

### 7. Not in scope yet

OAuth login (GitHub sign-in), SSO/SAML, 2FA and email verification
enforcement are not decided here. GitHub *repository* OAuth (Phase 19) is a
separate integration credential, not a login method, unless decided
otherwise later.

## Consequences

- XSS can't steal sessions through storage access. CSRF is handled by the
  XSRF token together with `SameSite=Lax`.
- The single-origin topology removes CORS misconfiguration as a risk class,
  but it requires the Nginx router in local development, which comes in
  Phase 02. Native, non-Docker development uses the documented split-origin
  fallback.

## Alternatives considered

- **JWT in `localStorage`:** rejected. Tokens readable from JavaScript are
  exposed to XSS.
- **Next.js-owned auth (NextAuth/Auth.js) with Laravel as a resource
  server:** rejected. It would put user identity in two systems.
