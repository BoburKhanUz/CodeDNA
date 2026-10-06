# Frontend Architecture (Next.js)

**Status: Phase 06.** It provides sign-in, registration, sign-out, a
protected application shell, the profile and password settings page, the
API client and the UI foundation. No project or analysis features exist
yet.

Related: [ADR-006](../decisions/ADR-006-authentication.md) (authentication),
[API reference](../api/README.md), [backend.md](backend.md),
[infrastructure.md](infrastructure.md).

## Stack

| Concern | Choice |
|---|---|
| Framework | Next.js 16.3 (App Router, Turbopack), React 19.2, TypeScript (strict) |
| Styling | Tailwind CSS v4, shadcn/ui (`new-york` style, Radix primitives, `lucide-react` icons) |
| Tests | Vitest 5 + Testing Library (jsdom) |
| Lint / types | ESLint (`eslint-config-next`), `tsc --noEmit` after `next typegen` |
| Runtime | Node 22 LTS in Docker, behind Nginx |

There is no global state library and no data-fetching library: the session
is resolved on the server, and the few client requests use a small
`fetch` wrapper.

## Code organization

```text
frontend/src/
├── app/                              App Router
│   ├── layout.tsx, page.tsx          root layout; public landing page (/)
│   ├── (auth)/layout.tsx             /login, /register: redirect signed-in users to /app
│   ├── (auth)/login/page.tsx
│   ├── (auth)/register/page.tsx
│   ├── app/layout.tsx                /app: server-side session gate + AppShell
│   ├── app/page.tsx, app/loading.tsx
│   ├── app/profile/page.tsx          /app/profile: account, developer profile, password
│   ├── error.tsx, not-found.tsx
├── components/
│   ├── ui/                           shadcn/ui: button, input, label, card, alert, textarea, native-select
│   ├── auth/                         login/register forms, logout button, AuthProvider, error alert
│   ├── profile/                      ProfileSettings (loader), ProfileForm, PasswordForm, field + validation
│   ├── app/app-shell.tsx             sidebar navigation (Home, Profile) + header with the signed-in user
│   └── brand/logo.tsx
├── lib/
│   ├── api/types.ts                  TypeScript mirror of the Laravel API contract
│   ├── api/errors.ts                 ApiError + user-facing messages
│   ├── api/http.ts                   response/envelope parsing (browser and server)
│   ├── api/client.ts                 browser client (same-origin, cookies, CSRF)
│   ├── auth/client.ts                login(), register(), logout()
│   ├── auth/session.ts               getSession(): server-side GET /api/v1/me
│   ├── profile/client.ts             getProfile(), updateProfile(), changePassword()
│   ├── profile/options.ts            labels for locales and languages; time zone suggestions
│   ├── config.server.ts              server-only env (BACKEND_INTERNAL_URL, FRONTEND_URL)
│   └── utils.ts                      shadcn `cn` helper
└── test/                             test helpers (API response builders, router mock)
```

The contract types live in `lib/api/types.ts`, next to their only
consumer. When a second TypeScript consumer appears, or an OpenAPI
description is introduced, they move to `packages/types` and are generated.

## Routes

| Route | Rendering | Behavior |
|---|---|---|
| `/` | static | Public landing page with "Sign in" and "Create account". No API call. |
| `/login` | dynamic | Signed in → `307` to `/app`. Otherwise the sign-in form. `?reason=password-changed` shows a fixed notice. |
| `/register` | dynamic | Signed in → `307` to `/app`. Otherwise the registration form. |
| `/app` | dynamic | Signed out → `307` to `/login`. Otherwise the app shell with the current user. |
| `/app/profile` | dynamic | Signed out → `307` to `/login` (same layout gate). Account identity, developer profile form, password change. |
| anything else | — | Not-found page. |

There is no `?next=` return-URL parameter, so there is no open-redirect
surface. Successful sign-in always goes to `/app`. The login page's
`reason` parameter is only compared with `password-changed`; it is never
rendered or used as a navigation target.

## Authentication

Laravel Sanctum SPA cookie sessions (ADR-006). The frontend never sees or
stores a token: the session cookie is HttpOnly, and nothing is written to
`localStorage`, `sessionStorage` or IndexedDB.

### Session resolution (server)

`lib/auth/session.ts` → `getSession()` runs in Server Components:

1. If the browser sent no cookies, the visitor is **unauthenticated**, with
   no API call.
2. Otherwise it calls `GET {BACKEND_INTERNAL_URL}/api/v1/me`
   (`http://nginx` inside Docker) server to server, and forwards:
   - the browser's `Cookie` header
   - `Origin` and `Referer` set to `FRONTEND_URL`, so Sanctum treats the
     call as first-party
   - `X-Forwarded-For`, so rate limits apply to the real client
3. `200` → `{status: "authenticated", user}`. `401` →
   `{status: "unauthenticated"}`. **Anything else throws**: a network
   failure or `5xx` shows the error page instead of silently looking like
   "logged out".
4. It is wrapped in React `cache()`, so a layout and its page share one `/me`
   call per request.

The three states map as follows:

| State | Where it lives |
|---|---|
| *loading* | Server render in progress. Next.js streams; `app/app/loading.tsx` covers page loads inside the shell |
| *authenticated* | `/app` renders; `AuthProvider` exposes the user to client components (`useCurrentUser()`) |
| *unauthenticated* | `/app` redirects to `/login` |

**Security model:** the `/app` gate is navigation and UX protection. It
means signed-out visitors never receive app markup. Laravel authorizes
every API request on its own and remains the source of truth.

### Browser flow (client)

```text
LoginForm ──► lib/auth/client.login() ──► api.post("/api/v1/auth/login")
                │ no XSRF-TOKEN cookie? GET /sanctum/csrf-cookie first
                │ send X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>, credentials: "include"
                │ 419? refresh the cookie once and retry once
                ▼
        200 → router.replace("/app") + router.refresh()
```

- **Register** works the same way. A `201` means the user is already signed
  in.
- **Logout:** `POST /api/v1/auth/logout`, then go to `/login`. A `401`
  (session already expired) counts as success. A network or server failure
  keeps the user in place with an error message.

## Profile and password (`/app/profile`)

- **Account** card: name and email from the server-resolved session, with
  "(not verified)" while email verification is not active. They are
  read-only for now.
- **Developer profile**: `ProfileSettings` loads `GET /api/v1/profile` with
  the shared API client and shows a loading state (`role="status"`), an
  error with a retry, or `ProfileForm`. The form validates on the client
  (lengths, `https://` URLs, GitHub username, two-letter country code,
  time zone required), sends every field with empty inputs as `null`, shows
  server field errors under each input, and on success stays on the page
  with "Profile saved." and the values the server returned (for example the
  uppercase country code).
  - Time zone is a text input with suggestions from
    `Intl.supportedValuesOf("timeZone")`. The server is authoritative and
    rejects legacy aliases that a browser may suggest.
  - Interface language (`en`, `uz`, `ru`) and preferred programming language
    are native selects. The locale is stored only; the UI is not translated.
  - Profile URLs are never rendered as links or images yet (no avatar
    preview), so a stored URL cannot trigger requests to third parties.
- **Password**: `PasswordForm` checks the current password is present, the
  new one meets the 8–72 policy, differs from the current one and matches
  the confirmation. On success it goes to `/login?reason=password-changed`,
  because the server ended the session. After a failed attempt the password
  inputs are cleared.
- A `401` from any of these calls (session ended) navigates to `/login`.

## API client (`lib/api`)

- **Same-origin only.** Paths must be absolute paths like `/api/v1/...`.
  Full and protocol-relative URLs are rejected. No API host is configured
  for the browser.
- `api.get/post/put/patch/delete<T>()` return the parsed body (`undefined`
  for `204`), or throw `ApiError`.
- `ApiError` carries `status` (`null` for network failures), `code` (the
  backend vocabulary plus client codes `NETWORK_ERROR` and
  `UNEXPECTED_RESPONSE`), `requestId`, `fieldErrors` (from
  `error.details.fields`) and `retryAfterSeconds` (from `Retry-After`).
- **Retries:** only the single CSRF refresh on `419`. Rate-limited (`429`)
  and failed requests are never retried automatically.

### Error presentation

UI text comes from `describeApiError()`. **Server-provided messages are
never rendered**, so the UI controls the wording and nothing internal can
leak.

| Case | Message |
|---|---|
| Network failure | "Unable to connect to CodeDNA. Check your connection and try again." |
| 401 | "Your session has ended. Please sign in again." |
| 403 | "You don't have permission to do that." |
| 419 (after retry) | "Your session expired. Please try again." |
| 422 `VALIDATION_FAILED` | field messages under each input, plus "Please correct the highlighted fields." |
| 422 `INVALID_CREDENTIALS` | "The email or password is incorrect." |
| 429 | "Too many attempts. Try again in N seconds." (from `Retry-After`) |
| 5xx / unexpected | "CodeDNA is having trouble right now…", plus `Reference: <request id>` |

Field-level validation messages come from Laravel's validator (for example
"The email has already been taken."). The error page (`error.tsx`) shows
only Next's error digest as a reference, never the error message.

## UI foundation

- shadcn/ui components are vendored in `components/ui` (generated by the
  `shadcn` CLI; see `components.json`). The theme is shadcn's neutral CSS
  variables in `app/globals.css`, with system fonts and no web font
  downloads.
- **Accessibility:**
  - every input has a `<label>`
  - errors are linked with `aria-describedby` and `aria-invalid`
  - form-level errors use `role="alert"`; loading states use
    `role="status"`
  - focus rings come from shadcn
  - semantic landmarks (`main`, `nav`, `header`, `aside`)
- No `dangerouslySetInnerHTML`, no third-party scripts, no analytics.
  `poweredByHeader: false`.

## Configuration

| Variable | Used by | Purpose |
|---|---|---|
| `BACKEND_INTERNAL_URL` | Next.js server only | Internal API base URL for session checks (`http://nginx` in Docker) |
| `FRONTEND_URL` | Next.js server only | Public origin sent as `Origin` to Sanctum (`http://localhost`) |

Neither variable has a `NEXT_PUBLIC_` prefix, so neither reaches the
browser bundle. `lib/config.server.ts` validates them and fails with a
clear message if one is missing or malformed.

## Testing

```bash
make test            # includes `npm test` (Vitest) in the frontend container
make lint-frontend   # npm run lint + npm run typecheck
```

The tests cover:

- response and envelope parsing
- the CSRF flow (fetch-before-POST, decoded token, single 419 retry, no
  retry on 429)
- same-origin enforcement and network errors
- user-facing messages
- server session resolution (cookie forwarding; 401 vs errors)
- the `/app` and `/login` redirects
- the login and register forms (client validation, server validation,
  invalid credentials, rate limit, server error with reference)
- logout, including an already-expired session
- the profile client, the profile loader (loading, error with retry, 401),
  the profile form (display, save, client and server validation, server
  errors, 401) and the password form (validation, success redirect, wrong
  current password, rate limit)
- the Profile navigation item and the login page's password-changed notice

Browser end-to-end checks are not yet part of the repository. Phases 04
and 06 verified the full browser flows with Playwright and Chromium against
the Docker stack (see the phase reports). Adding a committed Playwright suite
is planned for the QA phase. `make verify` covers the HTTP-level flow
through Nginx.

## Known limitations

- **Sliding session expiry:** server-side session checks don't forward
  Laravel's refreshed `Set-Cookie` to the browser. The browser's cookie
  expiry is extended only by client-side API calls. Long server-rendered-only
  browsing can therefore end a session after `SESSION_LIFETIME` minutes.
- Light theme only for now. The shadcn dark tokens exist but no toggle is
  wired up.
