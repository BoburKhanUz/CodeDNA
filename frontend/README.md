# frontend/ — Next.js UI

**Status: Phase 02 bootstrap.** This is a minimal Next.js 16 app (App
Router, TypeScript, Tailwind CSS, ESLint; created with `create-next-app`)
with a single placeholder page that proves the container is served through
Nginx. It has **no product UI** and no data. **Phase 04** will add the
frontend foundation: shadcn/ui, the API client, tests, and the route
structure.

What runs today (via Docker, see
[infrastructure.md](../docs/architecture/infrastructure.md)):

- `next dev` (Next.js 16.3, React 19.2, Node 22 LTS) behind Nginx at
  `http://localhost`, with hot reload over WebSocket.
- `node_modules` lives in a Docker volume, not on the host.

Responsibilities: user interface only. It talks exclusively to the public
Laravel API (`/api/v1`) and never to the analyzer, database or storage.
Authentication uses Sanctum SPA session cookies. Tokens are never stored in
`localStorage` or `sessionStorage`.

Planned routes: `/login`, `/register`, `/dashboard`, `/projects`,
`/repositories`, `/analyses`, `/dna`, `/profile`, `/settings`.

```bash
make shell-frontend        # bash in the container
npm run lint               # inside the container
```

- Architecture overview: [docs/architecture/overview.md](../docs/architecture/overview.md)
- Authentication: [ADR-006](../docs/decisions/ADR-006-authentication.md)
- API conventions: [docs/api/README.md](../docs/api/README.md)
