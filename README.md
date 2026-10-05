# CodeDNA

**CodeDNA is a developer intelligence platform.** It analyzes a developer's
real source code deterministically and builds an evolving, evidence-backed
**Developer DNA**: how they write code, their strengths and gaps, and how
they grow over time.

```text
ASSESS ─► ANALYZE ─► IDENTIFY GAPS ─► LEARN ─► PRACTICE ─► RE-ASSESS ─► UPDATE DNA ─┐
   ▲                                                                                │
   └────────────────────────────────────────────────────────────────────────────────┘
```

## Project status

| Phase | Scope | Status |
|---|---|---|
| 00 | Product specification, architecture, ADRs | ✅ Done |
| 01 | Monorepo foundation | ✅ Done |
| 02 | Docker infrastructure | ✅ Done |
| 03 | Laravel backend foundation | ⏭ Next |

**No product features exist yet.** The Docker environment runs every
component: Nginx, Laravel 13, Next.js 16, the FastAPI analyzer, PostgreSQL
16, Redis 7 and MinIO. The applications are minimal bootstraps (stock
Laravel skeleton, one placeholder page, one health endpoint). The full plan
is in [docs/architecture/overview.md](docs/architecture/overview.md#delivery-phases).

## Architecture at a glance

| Component | Technology | Directory |
|---|---|---|
| Frontend | Next.js 16 (App Router), TypeScript, Tailwind CSS, shadcn/ui | [`frontend/`](frontend/README.md) |
| Backend / product API | Laravel 13, PHP 8.4, PostgreSQL 16, Redis 7 | [`backend/`](backend/README.md) |
| Analysis engine | Python 3.11, FastAPI, Tree-sitter (internal-only) | [`analyzer/`](analyzer/README.md) |
| Source storage | S3-compatible API: MinIO locally, Cloudflare R2 in production | — |
| Infrastructure | Docker, Docker Compose, Nginx, GitHub Actions | [`docker/`](docker/README.md), [`.github/`](.github/workflows/ci.yml) |
| Shared contracts | OpenAPI / JSON Schema | [`packages/`](packages/README.md) |

Core rules: Laravel never parses code; the analyzer never owns business data
and is never publicly reachable; DNA scores are deterministic and versioned;
AI only interprets stored results, never produces scores.

## Documentation

- Product: [vision](docs/product/vision.md) · [MVP](docs/product/mvp.md)
- Architecture: [overview](docs/architecture/overview.md) · [infrastructure](docs/architecture/infrastructure.md) · [backend](docs/architecture/backend.md) · [analyzer & IR](docs/architecture/analyzer.md) · [data flow](docs/architecture/data-flow.md)
- API: [public conventions](docs/api/README.md) · [internal analyzer contract](docs/api/internal-analyzer-contract.md)
- Decisions: [ADR-001 stack](docs/decisions/ADR-001-stack.md) · [ADR-002 analysis engine](docs/decisions/ADR-002-analysis-engine.md) · [ADR-003 storage](docs/decisions/ADR-003-storage.md) · [ADR-004 DNA scoring](docs/decisions/ADR-004-dna-scoring.md) · [ADR-005 service communication](docs/decisions/ADR-005-service-communication.md) · [ADR-006 authentication](docs/decisions/ADR-006-authentication.md)

## Repository layout

```text
codedna/
├── backend/            Laravel 13 API (bootstrap; foundation in Phase 03)
├── frontend/           Next.js 16 UI (bootstrap; foundation in Phase 04)
├── analyzer/           Python analysis engine (health endpoint; Phase 08+)
├── docker/             Dockerfiles, Nginx and MinIO configuration
├── docs/               Product, architecture, API, ADRs
├── packages/           Shared API contracts and types      (Phases 03/04/08)
├── scripts/            Repository helper scripts
├── .github/workflows/  CI
├── docker-compose.yml  Development environment (7 services + minio-init)
├── .env.example        Documented environment variables (no secrets)
└── Makefile            Developer commands
```

## Getting started

Prerequisites: Git and Docker with Compose v2. Make and Bash are
recommended. For the static checks you also need Python 3.11+ and Node 22+.

```bash
git clone https://github.com/BoburKhanUz/CodeDNA.git && cd CodeDNA
make setup     # creates .env with random LOCAL secrets, builds the images
make up        # starts all services and waits until they are healthy
```

Open <http://localhost>.

| URL | Service |
|---|---|
| <http://localhost> | Next.js (through Nginx) |
| <http://localhost/up> | Laravel health route (through Nginx) |
| <http://localhost:9001> | MinIO console (credentials in `.env`) |
| `127.0.0.1:5432` / `127.0.0.1:6379` | PostgreSQL / Redis, for local tools |

Without Make: `./scripts/setup.sh && docker compose up -d --build --wait`.
If you create `.env` by hand (`cp .env.example .env`), fill in every value
marked `[required]` first. Compose refuses to start without them.

```bash
make ps / make logs / make logs s=backend
make shell-backend | shell-frontend | shell-analyzer
make test      # test suites inside the containers
make verify    # runtime smoke test: routing, networking, isolation, S3
make down      # stop (data volumes are kept)
make check     # static checks run in CI
```

The first start installs Composer and npm dependencies inside the
containers, so it takes a few minutes. Ports, volumes, environment
variables, MinIO usage and troubleshooting are covered in
[docs/architecture/infrastructure.md](docs/architecture/infrastructure.md).

## Security

Source code is treated as sensitive: never executed during analysis, stored
privately, and secrets found in it are reported by location only. They are
never logged or sent to AI providers. Never commit `.env` files or real
credentials. `.env.example` must keep secret variables empty, and CI enforces
this. Local services listen on 127.0.0.1 only. The analyzer runs on an
internal network with no internet access and is never exposed through
Nginx.
