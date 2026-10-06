# frontend/ — Next.js UI

**Status: Phase 04 foundation.** Next.js 16.3 (App Router), React 19.2,
TypeScript, Tailwind CSS v4 and shadcn/ui, served through Nginx at
<http://localhost>.

What exists:

- `/` public landing page; `/login`, `/register` (redirect to `/app` when
  signed in)
- `/app`: minimal authenticated shell (current user, sign out). Signed-out
  visitors get a `307` to `/login`.
- Laravel Sanctum SPA authentication: HttpOnly session cookie plus the
  `XSRF-TOKEN` → `X-XSRF-TOKEN` CSRF flow. No tokens in browser storage.
- A small same-origin API client (`src/lib/api`) that understands the
  Laravel `{"data"}` / `{"error"}` envelopes

There are **no product features yet** (no projects, analyses or DNA views).

```bash
make up              # runs `next dev` in Docker (hot reload via Nginx)
make test            # includes Vitest (npm test) for the frontend
make lint-frontend   # ESLint + TypeScript type check
make shell-frontend  # bash in the container (npm run …)
```

| npm script | Purpose |
|---|---|
| `dev` | Next.js dev server (the container's default command) |
| `build` / `start` | Production build and server |
| `lint` | ESLint |
| `typecheck` | `next typegen && tsc --noEmit` |
| `test` / `test:watch` | Vitest |

`node_modules` lives in a Docker volume. After changing dependencies, the
container's entrypoint re-installs on the next start (`package-lock.json` is
compared to a stamp).

- Architecture, auth flow and API client: [docs/architecture/frontend.md](../docs/architecture/frontend.md)
- API contract: [docs/api/README.md](../docs/api/README.md); TypeScript mirror in `src/lib/api/types.ts`
- Authentication decision: [ADR-006](../docs/decisions/ADR-006-authentication.md)
