# Frontend Architecture (Next.js)

**Status: Phase 07.** It provides sign-in, registration, sign-out, a
protected application shell, the profile and password settings page,
projects (list, create, detail, archive), ZIP source upload with snapshot
history, the API client and the UI foundation. No analysis features exist
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
│   ├── app/projects/page.tsx         /app/projects: project list
│   ├── app/projects/new/page.tsx     /app/projects/new: create a project
│   ├── app/projects/[project]/page.tsx  /app/projects/{id}: details, upload, snapshots, archive
│   ├── error.tsx, not-found.tsx
├── components/
│   ├── ui/                           shadcn/ui: button, input, label, card, alert, textarea, native-select
│   ├── auth/                         login/register forms, logout button, AuthProvider, error alert
│   ├── profile/                      ProfileSettings (loader), ProfileForm, PasswordForm, field + validation
│   ├── projects/                     ProjectList, ProjectForm, ProjectDetail, UploadSource, StatusBadge
│   ├── app/app-shell.tsx             sidebar navigation (Home, Projects, Profile) + header with the signed-in user
│   └── brand/logo.tsx
├── lib/
│   ├── api/types.ts                  TypeScript mirror of the Laravel API contract
│   ├── api/errors.ts                 ApiError + user-facing messages
│   ├── api/http.ts                   response/envelope parsing (browser and server)
│   ├── api/client.ts                 browser client (same-origin, cookies, CSRF); upload() for multipart with progress
│   ├── auth/client.ts                login(), register(), logout()
│   ├── auth/session.ts               getSession(): server-side GET /api/v1/me
│   ├── profile/client.ts             getProfile(), updateProfile(), changePassword()
│   ├── profile/options.ts            labels for locales and languages; time zone suggestions
│   ├── projects/client.ts            list/get/create/archive projects, list snapshots, uploadSource()
│   ├── projects/format.ts            sizes, UTC dates, labels, slugify (plain text only)
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
| `/app/projects` | dynamic | Same gate. The developer's projects (paginated), or an empty state. |
| `/app/projects/new` | dynamic | Same gate. Create-project form; opens the new project. |
| `/app/projects/[project]` | dynamic | Same gate. Project details, CodeDNA card, ZIP upload (active upload projects only), snapshot history, archive. Unknown, foreign or malformed IDs show "Project not found". |
| `/app/projects/[project]/dna` | dynamic | Same gate. CodeDNA dashboard of the newest assessment, or an empty state. |
| `/app/projects/[project]/dna/[snapshot]` | dynamic | Same gate. One assessment from the history. Unknown or foreign IDs show "Assessment not found" / "Project not found". |
| `/app/projects/[project]/competencies` | dynamic | Same gate. Competency Matrix of the newest competency snapshot, or an empty state. |
| `/app/projects/[project]/skill-gaps` | dynamic | Same gate. Skill gaps of the newest skill gap snapshot against the target profile, or an empty state. |
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

## Projects and source upload (`/app/projects`)

- **List:** name (link), slug, language, status badge, source type, created
  date (UTC), with Previous/Next paging from the API's `meta`. Loading,
  error-with-retry and empty states.
- **Create:** name, slug (derived from the name until edited), description,
  source type (Upload, or Repository with an `https://` URL; repositories
  are recorded only), language, default branch. Client checks mirror the
  backend rules; server field errors appear under each input. Success opens
  the project.
- **Detail:** project facts, then the source card and the snapshot table
  (version, created, files, size, language, first 12 hex digits of the
  SHA-256 with the full hash as a tooltip). Archive is a two-step,
  irreversible action; afterwards the upload form disappears and history
  stays.
- **Upload (`UploadSource`):** a `.zip` file input with early checks
  (extension, non-empty, at most 50 MiB; the server is authoritative). It
  uses `upload()` from the shared API client, which posts multipart form data
  with `XMLHttpRequest`, the only browser API that reports upload progress,
  with the same CSRF handling (cookie bootstrap, one retry on `419`) and
  error parsing as every other call. A progress bar shows the percentage,
  then "Checking and storing the archive…". Success says
  **"Source snapshot vN created."** and nothing about analysis. Each chosen
  file gets one `Idempotency-Key` (`crypto.randomUUID()`), reused when
  retrying after a failure, so a retry can never create a second snapshot.
- **Security:** everything is rendered as text: names, descriptions and
  URLs from the API are never interpreted as HTML, and archive contents or
  entry names are never shown (the API does not return them). No storage
  keys or URLs exist in the UI, and nothing is stored in browser storage.
  Project IDs are checked to be ULIDs before any URL is built.
- Dates are shown in UTC. Using the profile's time zone preference is
  future work.

## CodeDNA dashboard (`/app/projects/[project]/dna`)

> The CodeDNA Dashboard visualizes deterministic results produced by the CodeDNA scoring engine. It does not infer developer seniority, intelligence, personality, or professional level.

Presentation only (Phase 12). Data comes from the read-only
[DNA API](../api/README.md#dna) (`lib/dna/client.ts`); types are in
`lib/api/types.ts` (`DnaSnapshot`, `DnaDimension`, `DnaComponent`, …).

- **No client-side scoring.** `lib/dna/format.ts` only reformats the API's
  decimal strings, digit by digit: `"0.8050"` → `80.50` (overall and
  dimension scores, shown "/ 100"), `"0.9000"` → `90.00%` (data quality),
  weights as `40%`, contributions as `32.50 points`. No score, weight, share
  or contribution is computed in TypeScript; a test renders a deliberately
  inconsistent payload to prove values are shown as given.
- **Missing is never 0.** `null` renders as "Insufficient data", "Not
  scored", "Not available" or "—", never as `0`. `INSUFFICIENT_DATA`
  explains that the source lacked enough supported evidence; unavailable
  dimensions show their reason; evidence shows the backend status
  (`Insufficient evidence`, `Not supported for the analyzed languages`,
  `Not available in the analysis result`).
- **Layout.** Header (project, scoring version, calculation date, source
  snapshot version); overall score card and data-quality card (with the
  documented formula terms in a disclosure; never called confidence or
  probability); one card per dimension with score, weight, redistributed
  weight when it differs, contribution, data quality and an evidence
  disclosure listing each measurement's counts, measured value, component
  score, weight and thresholds; source and calculation card (snapshot, files
  parsed, analysis run, scoring version, specification fingerprint, result
  hash); history of earlier assessments when there is more than one.
  Neutral bars (`ScoreBar`, `role="meter"`), no color scale and no
  qualitative labels.
- **States.** Skeleton while loading; "No CodeDNA assessment is available
  yet." when the project has none, with the newest static analysis's real
  status (queued/running, completed but not yet scored, failed) from the
  analyses API and no simulated progress (analyses are started through the
  API; there is no analysis screen); API errors through `ApiErrorAlert`
  with the request reference and a retry; 401 → `/login`; 404 → "Project
  not found" / "Assessment not found".
- **Project page.** A CodeDNA card shows the newest assessment's score (or
  "Insufficient data" / "No assessment available.") and data quality, and
  links to the dashboard.

## Competency Matrix (`/app/projects/[project]/competencies`)

> CodeDNA competency results describe deterministic evidence observed in analyzed source code. They do not establish developer seniority, intelligence, personality, professional level, or future potential.

Presentation only (Phase 13), from the read-only
[competency API](../api/README.md#competencies) (`lib/competency/client.ts`,
types in `lib/api/types.ts`). Reached from the CodeDNA dashboard's
"Competency Matrix" card.

- One card per competency: score (backend decimal shown on 0–100 by the
  same digit-based formatter as CodeDNA), level badge ("Level 2 ·
  Established"), a neutral sentence about the code ("Available source-code
  evidence meets the defined threshold for this competency."), evidence
  quality (never called confidence), evidence available, and partially
  supported languages.
- "Why this result" expands every piece of evidence: its CodeDNA source
  (`COMPLEXITY.mean_cyclomatic_complexity`), rationale, raw counts, measured
  value, evidence score, weight and thresholds, plus the evidence-quality
  terms and the level boundaries of the version.
- Not assessed competencies show their status (Insufficient evidence,
  Unsupported evidence, Evidence unavailable) and "This is not a low
  result." — no score, level, bar or 0. A snapshot with nothing assessed
  shows an "Insufficient evidence" notice.
- Provenance: CodeDNA assessment (link), source snapshot, measured
  languages, analysis run, versions and fingerprint.
- States: skeleton, no matrix (says whether a CodeDNA assessment exists),
  API error with reference and retry, 401 → `/login`, 404 → "Project not
  found". No score, level, weight or threshold is computed in the browser;
  a test renders an inconsistent payload to prove values are shown as given.

## Skill gaps (`/app/projects/[project]/skill-gaps`)

> Skill Gap results describe measurable differences between observed source-code competency evidence and a versioned target definition. They do not establish developer seniority, intelligence, personality, professional worth, or future potential.

Presentation only (Phase 14), from the read-only
[skill gap API](../api/README.md#skill-gaps) (`lib/skill-gap/client.ts`).
Reached from the Competency Matrix's "Skill Gaps" card; links back to the
project, CodeDNA and the Competency Matrix.

- Target profile card: profile and version, its description, the
  calibration notice, material-gap and priority thresholds, and the counts
  of material gaps per priority (backend counts; there is no aggregate).
- One card per competency: status ("Material gap", "No material gap", …),
  priority badge, current and target scores with neutral bars, "Gap: 30.00
  points" (immaterial raw gaps are shown and marked), the capped-priority
  note, evidence quality, competency level as context, partially supported
  languages, and an evidence disclosure with the target rationale and each
  CodeDNA source.
- Unmeasured competencies show "Insufficient evidence to determine this
  competency gap." (or the unsupported / unavailable / not targeted text)
  with the target, and never a gap or priority.
- "No material competency gaps were identified against the selected
  engineering standard." for `NO_MATERIAL_GAPS`; a separate "Insufficient
  evidence" notice for `INSUFFICIENT_DATA`; an empty state when no analysis
  exists (says whether a competency matrix exists); skeleton, error with
  reference and retry, 401 → `/login`, 404 → "Project not found".
- Fixed product text only; no recommendations, courses or generated prose.
  No gap, priority, target or score is computed in the browser; a test
  renders an inconsistent payload to prove values are shown as given.

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
- the upload transport (progress, CSRF header and retry, idempotency
  header, network errors, same-origin enforcement), the projects client
  (paths, ULID-only URLs), formatting and slugs
- the project list (loading, empty, paging, retry, 401), the create form
  (slug derivation, validation, repository URL, server errors), the project
  detail (plain-text rendering, archive confirmation, repository and
  not-found states) and the upload panel (client checks, progress, success
  text, rejected archives, idempotent retry, new key per file, 401)

Browser end-to-end checks are not yet part of the repository. Phases 04, 06
and 07 verified the full browser flows with Playwright and Chromium against
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
