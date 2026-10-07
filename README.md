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
| 03 | Laravel backend foundation | ✅ Done |
| 04 | Next.js frontend foundation | ✅ Done |
| 05 | Database and domain model | ✅ Done |
| 06 | Authentication and developer profile | ✅ Done |
| 07 | Projects and source management | ✅ Done |
| 08 | Python analyzer foundation | ✅ Done |
| 09 | AST and static analysis | ✅ Done |
| 10 | Analysis queue and pipeline | ✅ Done |
| 11 | CodeDNA scoring engine | ✅ Done |
| 12 | DNA dashboard | ✅ Done |
| 13 | Competency matrix | ✅ Done |
| 14 | Skill gap analysis | ✅ Done |
| 15 | AI assessment and interpretation | ✅ Done |
| 16 | Coding challenges | ✅ Done |
| 17 | Learning roadmap | ✅ Done |
| 18 | Growth tracking | ✅ Done |

The Docker environment runs every component: Nginx, Laravel 13, Next.js 16,
the FastAPI analyzer, the challenge evaluator, PostgreSQL 16, Redis 7 and
MinIO. You can register, sign in and sign out, edit your
developer profile, change your password, create projects and upload ZIP
archives of their source (stored as immutable, versioned snapshots in
MinIO; nothing is executed or analyzed) at
<http://localhost> (Next.js UI, Laravel Sanctum session cookies; see
[frontend](docs/architecture/frontend.md) and the [API reference](docs/api/README.md)).
The internal analyzer (Phases 08–09) can securely fetch, extract and
inventory a snapshot, parse ten languages with Tree-sitter (nothing is
executed) and, on request, return a versioned static-analysis result (IR 1.1,
deterministic metrics and structural findings; see
[metrics-v1.md](docs/architecture/metrics-v1.md)). Since Phase 10 the API
can start an analysis of a snapshot (`foundation` or `static_analysis`): a
Redis queue worker calls the analyzer, verifies the signed result and its
hash, and stores it as an analysis run's result
([data-flow.md](docs/architecture/data-flow.md#analysis-pipeline-phase-10)).
Since Phase 11 every successful static-analysis result is scored
deterministically into an immutable DNA snapshot (scoring version 1.0.0,
[dna-scoring-v1.md](docs/architecture/dna-scoring-v1.md)); CodeDNA v1
measures characteristics of the analyzed code and makes no claims about
developers. Since Phase 12 each project has a CodeDNA dashboard that shows
these snapshots (score, data quality, dimensions and their evidence) from a
read-only API; there is no analysis screen yet. Since Phase 13 each DNA
snapshot also yields a deterministic competency matrix
([competency-matrix-v1.md](docs/architecture/competency-matrix-v1.md)) that
describes evidence in the code, never a developer's seniority or level, and
since Phase 14 a skill gap analysis against a versioned engineering target
([skill-gap-v1.md](docs/architecture/skill-gap-v1.md)). Since Phase 15 the
owner can request an AI-generated, non-authoritative interpretation of a
skill gap analysis; every statement cites the stored evidence, and the AI
never determines or changes a score
([ai-assessment-v1.md](docs/architecture/ai-assessment-v1.md); disabled
unless `AI_ENABLED=true`). Since Phase 16 the owner can practice on coding
challenges selected deterministically from a project's skill gaps. Python
submissions run only in a dedicated sandbox service with no network, no
secrets and strict resource limits, and are graded by deterministic tests and
code-structure rules. **Challenge completion ≠ CodeDNA improvement:** a
passed challenge never changes a score, competency or skill gap; only a new
analysis of new code does
([coding-challenges-v1.md](docs/architecture/coding-challenges-v1.md),
[challenge-evaluator.md](docs/architecture/challenge-evaluator.md)). Since Phase 17 a
learning roadmap, generated deterministically (no AI) from the newest skill gap
analysis, sets a development focus and short, ordered learning steps per
competency, linked to the coding challenges. **Roadmap ≠ assessment:**
completing learning steps never changes a score or gap; improvement is measured
only through new code analysis
([learning-roadmap-v1.md](docs/architecture/learning-roadmap-v1.md)). Since Phase 18
growth tracking compares each new code assessment with the immediately preceding
comparable one: CodeDNA dimensions, competencies (with level transitions) and skill
gaps, read-only and without AI. **Growth ≠ learning:** steps and challenges are
shown only as context; no baseline is not zero, and incomparable or insufficient
evidence is never regression
([growth-tracking-v1.md](docs/architecture/growth-tracking-v1.md)). The full plan is in
[docs/architecture/overview.md](docs/architecture/overview.md#delivery-phases).

## Architecture at a glance

| Component | Technology | Directory |
|---|---|---|
| Frontend | Next.js 16 (App Router), TypeScript, Tailwind CSS, shadcn/ui | [`frontend/`](frontend/README.md) |
| Backend / product API | Laravel 13, PHP 8.4, PostgreSQL 16, Redis 7 | [`backend/`](backend/README.md) |
| Analysis engine | Python 3.11, FastAPI, Tree-sitter (internal-only) | [`analyzer/`](analyzer/README.md) |
| Challenge evaluator | Python 3.11, standard library only; no network, sandboxed | [`evaluator/`](evaluator/README.md) |
| Source storage | S3-compatible API: MinIO locally, Cloudflare R2 in production | — |
| Infrastructure | Docker, Docker Compose, Nginx, GitHub Actions | [`docker/`](docker/README.md), [`.github/`](.github/workflows/ci.yml) |
| Shared contracts | OpenAPI / JSON Schema | [`packages/`](packages/README.md) |

Core rules: Laravel never parses code; the analyzer never owns business data
and is never publicly reachable; DNA scores are deterministic and versioned;
AI only interprets stored results, never produces scores; submitted challenge
code runs only in the network-less evaluator and never changes CodeDNA.

## Documentation

- Product: [vision](docs/product/vision.md) · [MVP](docs/product/mvp.md)
- Architecture: [overview](docs/architecture/overview.md) · [infrastructure](docs/architecture/infrastructure.md) · [backend](docs/architecture/backend.md) · [data model](docs/architecture/data-model.md) · [frontend](docs/architecture/frontend.md) · [analyzer & IR](docs/architecture/analyzer.md) · [data flow](docs/architecture/data-flow.md) · [coding challenges](docs/architecture/coding-challenges-v1.md) · [challenge evaluator](docs/architecture/challenge-evaluator.md) · [learning roadmap](docs/architecture/learning-roadmap-v1.md) · [growth tracking](docs/architecture/growth-tracking-v1.md)
- API: [public conventions](docs/api/README.md) · [internal analyzer contract](docs/api/internal-analyzer-contract.md)
- Decisions: [ADR-001 stack](docs/decisions/ADR-001-stack.md) · [ADR-002 analysis engine](docs/decisions/ADR-002-analysis-engine.md) · [ADR-003 storage](docs/decisions/ADR-003-storage.md) · [ADR-004 DNA scoring](docs/decisions/ADR-004-dna-scoring.md) · [ADR-005 service communication](docs/decisions/ADR-005-service-communication.md) · [ADR-006 authentication](docs/decisions/ADR-006-authentication.md) · [ADR-007 AI interpretation](docs/decisions/ADR-007-ai-interpretation.md) · [ADR-008 coding challenges](docs/decisions/ADR-008-coding-challenges.md) · [ADR-009 learning roadmap](docs/decisions/ADR-009-learning-roadmap.md) · [ADR-010 growth tracking](docs/decisions/ADR-010-growth-tracking.md)

## Repository layout

```text
codedna/
├── backend/            Laravel 13 API (bootstrap; foundation in Phase 03)
├── frontend/           Next.js 16 UI (auth foundation)
├── analyzer/           Python analysis engine (foundation: signed intake, safe extraction, discovery)
├── evaluator/          Challenge sandbox (Phase 16: no network, unprivileged slot users)
├── docker/             Dockerfiles, Nginx and MinIO configuration
├── docs/               Product, architecture, API, ADRs
├── packages/           Shared API contracts and types      (Phases 03/04/08)
├── scripts/            Repository helper scripts
├── .github/workflows/  CI
├── docker-compose.yml  Development environment (services + minio-init)
├── .env.example        Documented environment variables (no secrets)
└── Makefile            Developer commands
```

## Getting started

Prerequisites: Git and Docker with Compose v2. Make and Bash are
recommended. For the static checks you also need Python 3.11+ and Node 22+.

```bash
git clone https://github.com/BoburKhanUz/CodeDNA.git && cd CodeDNA
make setup     # creates .env with random LOCAL secrets, builds the images
make up        # starts all services, waits until healthy, runs migrations
```

Open <http://localhost>.

| URL | Service |
|---|---|
| <http://localhost> | Next.js: landing page, `/login`, `/register`, `/app` |
| <http://localhost/api/v1/health> | API readiness (database, Redis) |
| <http://localhost/up> | Laravel liveness route (through Nginx) |
| <http://localhost:9001> | MinIO console (credentials in `.env`) |
| `127.0.0.1:5432` / `127.0.0.1:6379` | PostgreSQL / Redis, for local tools |

Without Make: `./scripts/setup.sh && docker compose up -d --build --wait`.
If you create `.env` by hand (`cp .env.example .env`), fill in every value
marked `[required]` first. Compose refuses to start without them.

```bash
make ps / make logs / make logs s=backend
make shell-backend | shell-frontend | shell-analyzer
make test      # analyzer pytest + backend PHPUnit (dedicated test DB) + frontend Vitest + evaluator sandbox tests
make lint-backend   # Laravel Pint style check
make lint-frontend  # ESLint + TypeScript type check
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
Nginx. Code submitted for coding challenges is the one thing that is
executed. It runs only in the evaluator, which has no network interface,
no credentials, a read-only filesystem and per-run unprivileged users with
resource limits ([challenge-evaluator.md](docs/architecture/challenge-evaluator.md)).
