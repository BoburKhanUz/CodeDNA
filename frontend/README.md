# frontend/ — Next.js UI

**Status: not created yet.** The Next.js application (App Router, TypeScript,
Tailwind CSS, shadcn/ui) is scaffolded in **Phase 04** (frontend foundation).

Responsibilities: user interface only. It talks exclusively to the public
Laravel API (`/api/v1`) and never to the analyzer, database or storage.
Authentication uses Sanctum SPA session cookies; tokens are never stored in
`localStorage` or `sessionStorage`.

Initial routes: `/login`, `/register`, `/dashboard`, `/projects`,
`/repositories`, `/analyses`, `/dna`, `/profile`, `/settings`.

- Architecture overview: [docs/architecture/overview.md](../docs/architecture/overview.md)
- Authentication: [ADR-006](../docs/decisions/ADR-006-authentication.md)
- API conventions: [docs/api/README.md](../docs/api/README.md)

The exact Next.js and Node versions are recorded here when the application is
created ([ADR-001 version policy](../docs/decisions/ADR-001-stack.md#version-policy)).
