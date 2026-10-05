# Architecture Overview

CodeDNA is a developer intelligence platform. It analyzes real source code
deterministically, turns it into a versioned "Developer DNA" profile, and
later uses that evidence for competencies, skill gaps, learning and growth
tracking. See [product vision](../product/vision.md) and
[MVP definition](../product/mvp.md).

**Current status:** Phase 02 (Docker infrastructure) complete. The local
environment runs every component, but the applications are stock bootstraps
with no product features yet. Everything below is the target architecture.

## System context

```text
                         ┌──────────────────────────── public ────────────────────────────┐
  Developer (browser) ──►│ Nginx  ── /api/*, /sanctum/* ──► Laravel (php-fpm)              │
                         │        ── everything else    ──► Next.js (node)                 │
                         └─────────────────────────────────────┬──────────────────────────┘
                                                               │
                         ┌─────────────────────────── private network ────────────────────┐
                         │  Laravel API / queue workers / scheduler                        │
                         │      │            │              │                              │
                         │  PostgreSQL     Redis      Object storage (S3 API; R2 in prod)  │
                         │      ▲  (queue, cache,           ▲                              │
                         │      │   sessions)               │ pre-signed GET               │
                         │      │                           │                              │
                         │  Laravel worker ── HMAC-signed HTTP ──► Python analyzer          │
                         │                                         (FastAPI; no DB, no      │
                         │                                          queue, no credentials)  │
                         └─────────────────────────────────────────────────────────────────┘
```

## Components

| Component | Directory | Owns | Docs |
|---|---|---|---|
| Frontend | `frontend/` | UI only: dashboard, projects, analyses, DNA views | (Phase 04) |
| Backend | `backend/` | Auth, domain, persistence, orchestration, authorization, business rules | [backend.md](backend.md) |
| Analyzer | `analyzer/` | Parsing, IR, metrics, features, deterministic DNA scoring | [analyzer.md](analyzer.md) |
| AI interpretation | (Phase 15) | Explaining stored deterministic results; never producing scores | ADR to be written in Phase 15 |
| Infrastructure | `docker/`, `docker-compose.yml`, `.github/` | Containers, routing, CI | [infrastructure.md](infrastructure.md) |
| Contracts | `packages/api-contracts/` | OpenAPI (public) and JSON Schema (internal analyzer) | [api/](../api/README.md) |

## Boundaries (non-negotiable)

1. **The frontend talks only to the public Laravel API.** It never talks to
   the analyzer, the database or storage.
2. **Laravel never parses code.** The analyzer never owns business data.
3. **The analyzer is internal-only**, authenticated with HMAC, and holds no
   credentials ([ADR-005](../decisions/ADR-005-service-communication.md)).
4. **Scores are deterministic and versioned.** AI interprets them and never
   generates them ([ADR-004](../decisions/ADR-004-dna-scoring.md)).
5. **Repository content is untrusted.** It is never executed, and secrets in
   it are never logged, stored as values, or sent to an AI provider.

## Data flow

See [data-flow.md](data-flow.md) for the analysis sequence, the run state
machine, timeouts and retries, and data classification.

## Environments and domains

| Environment | Public origin | Notes |
|---|---|---|
| Local (Docker) | `http://localhost` | Nginx single origin; MinIO storage. The analyzer has no published port ([infrastructure.md](infrastructure.md)). |
| Local (native, no Docker) | `localhost:3000` (Next.js) + `localhost:8000` (Laravel) | Split-origin fallback ([ADR-006](../decisions/ADR-006-authentication.md)) |
| Production | `https://app.<your-domain>` | Nginx single origin, TLS, R2 storage |

## Decision records

| ADR | Topic |
|---|---|
| [ADR-001](../decisions/ADR-001-stack.md) | Technology stack and version policy |
| [ADR-002](../decisions/ADR-002-analysis-engine.md) | Tree-sitter parsing and the versioned IR |
| [ADR-003](../decisions/ADR-003-storage.md) | Source storage (S3 API, R2, pre-signed URLs) |
| [ADR-004](../decisions/ADR-004-dna-scoring.md) | DNA scoring: determinism, versioning, evidence |
| [ADR-005](../decisions/ADR-005-service-communication.md) | Laravel ↔ analyzer communication |
| [ADR-006](../decisions/ADR-006-authentication.md) | Authentication (Sanctum SPA cookies, tokens later) |

New ADRs use the next free number and follow the same format: Status, Date,
Context, Decision, Consequences, Alternatives considered, and Open questions
if there are any. An accepted ADR is changed only by superseding it with a
new ADR. Errata and clarifications are the exception.

## Delivery phases

The project is built in the 26 phases (00–25) of the master instruction, one
at a time:

| Phase | Scope | Status |
|---|---|---|
| 00 | Product definition, technical specification, ADRs | Done |
| 01 | Monorepo foundation | Done |
| 02 | Docker infrastructure | Done |
| 03 | Laravel backend foundation | Next |
| 04 | Next.js frontend foundation | — |
| 05 | Database and domain model | — |
| 06 | Authentication and developer profile | — |
| 07 | Projects and repository management (ZIP upload) | — |
| 08 | Python analyzer foundation (IR frozen first) | — |
| 09 | AST and static analysis (PHP → Python → JS → TS) | — |
| 10 | Analysis queue and pipeline | — |
| 11 | CodeDNA scoring engine | — |
| 12 | DNA dashboard (**MVP complete**) | — |
| 13–25 | Competencies, skill gaps, AI, challenges, learning, growth, GitHub, history, hardening, QA, billing, teams, production | — |
