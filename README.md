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
| 02 | Docker infrastructure | ⏭ Next |

**No application code exists yet.** `backend/`, `frontend/` and `analyzer/`
contain only READMEs describing what each phase will add. The full plan is in
[docs/architecture/overview.md](docs/architecture/overview.md#delivery-phases).

## Architecture at a glance

| Component | Technology | Directory |
|---|---|---|
| Frontend | Next.js (App Router), TypeScript, Tailwind CSS, shadcn/ui | [`frontend/`](frontend/README.md) |
| Backend / product API | Laravel, PHP, PostgreSQL, Redis queues | [`backend/`](backend/README.md) |
| Analysis engine | Python, FastAPI, Tree-sitter (internal-only) | [`analyzer/`](analyzer/README.md) |
| Source storage | S3-compatible API (Cloudflare R2 in production) | — |
| Infrastructure | Docker, Docker Compose, Nginx, GitHub Actions | [`docker/`](docker/README.md), [`.github/`](.github/workflows/ci.yml) |
| Shared contracts | OpenAPI / JSON Schema | [`packages/`](packages/README.md) |

Core rules: Laravel never parses code; the analyzer never owns business data
and is never publicly reachable; DNA scores are deterministic and versioned;
AI only interprets stored results, never produces scores.

## Documentation

- Product: [vision](docs/product/vision.md) · [MVP](docs/product/mvp.md)
- Architecture: [overview](docs/architecture/overview.md) · [backend](docs/architecture/backend.md) · [analyzer & IR](docs/architecture/analyzer.md) · [data flow](docs/architecture/data-flow.md)
- API: [public conventions](docs/api/README.md) · [internal analyzer contract](docs/api/internal-analyzer-contract.md)
- Decisions: [ADR-001 stack](docs/decisions/ADR-001-stack.md) · [ADR-002 analysis engine](docs/decisions/ADR-002-analysis-engine.md) · [ADR-003 storage](docs/decisions/ADR-003-storage.md) · [ADR-004 DNA scoring](docs/decisions/ADR-004-dna-scoring.md) · [ADR-005 service communication](docs/decisions/ADR-005-service-communication.md) · [ADR-006 authentication](docs/decisions/ADR-006-authentication.md)

## Repository layout

```text
codedna/
├── backend/            Laravel API                         (Phase 03)
├── frontend/           Next.js UI                          (Phase 04)
├── analyzer/           Python analysis engine              (Phase 08)
├── docker/             Container build contexts            (Phase 02)
├── docs/               Product, architecture, API, ADRs
├── packages/           Shared API contracts and types      (Phases 03/04/08)
├── scripts/            Repository helper scripts
├── .github/workflows/  CI
├── docker-compose.yml  Backing services: PostgreSQL 16, Redis 7
├── .env.example        Documented environment variables (no secrets)
└── Makefile            Developer commands
```

## Getting started (Phase 01)

Prerequisites: Git, Python 3.11+, Node 22+ (for Markdown linting), Docker
with Compose v2 (for backing services).

```bash
make help            # list commands
make check-repo      # required files, links, .env.example hygiene
make lint-docs       # Markdown lint

make env             # create .env from .env.example, then set DB_PASSWORD
make infra-up        # start PostgreSQL 16 + Redis 7 on 127.0.0.1
make infra-down
```

`make check` runs every foundation check that CI runs except the secret scan
(`make scan-secrets`, requires gitleaks). YAML and workflow linting need
`pip install yamllint actionlint-py`.

## Security

Source code is treated as sensitive: never executed during analysis, stored
privately, and secrets found in it are reported by location only — never
logged or sent to AI providers. Never commit `.env` files or real
credentials; `.env.example` must keep secret variables empty (enforced in CI).
