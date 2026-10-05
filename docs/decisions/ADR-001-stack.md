# ADR-001: Technology Stack

- **Status:** Accepted (amended 2026-10-05, Phase 02: major versions confirmed — see “Amendment”)
- **Date:** 2026-10-05
- **Deciders:** Project owner, lead architect
- **Related:** [ADR-002](ADR-002-analysis-engine.md), [ADR-005](ADR-005-service-communication.md), [ADR-006](ADR-006-authentication.md)

## Context

CodeDNA has three distinct kinds of work:

1. **Product/business logic** — users, projects, repositories, analysis
   orchestration, persistence, authorization, billing later.
2. **User interface** — a data-oriented developer dashboard.
3. **Code analysis** — parsing many languages into ASTs, extracting features,
   computing deterministic metrics and DNA scores.

These have different ecosystem strengths. The analysis engine is the
long-term core intellectual property and must not be constrained by the web
framework.

## Decision

| Layer | Technology | Version line | Notes |
|---|---|---|---|
| Frontend | Next.js (App Router), React, TypeScript | Next.js **16.x**, React 19, Node **22 LTS** | Tailwind CSS + shadcn/ui |
| Backend | Laravel, PHP | Laravel **13.x**, PHP **8.4** (framework minimum 8.3) | REST API under `/api/v1` |
| Analyzer | Python, FastAPI | Python **3.11**, FastAPI current stable | Internal-only HTTP service |
| Parsing | Tree-sitter via `py-tree-sitter` | current stable | See ADR-002 |
| Database | PostgreSQL | **16** | Owned exclusively by Laravel |
| Queue / cache / sessions | Redis | **7** | Laravel queues only (see ADR-005) |
| Object storage | S3-compatible API | — | MinIO locally, Cloudflare R2 in production (ADR-003) |
| Runtime packaging | Docker, Docker Compose, Nginx | Nginx **1.28** | [infrastructure.md](../architecture/infrastructure.md) |
| CI | GitHub Actions | — | |

### Testing and quality tooling

| Component | Tests | Lint / format / static analysis |
|---|---|---|
| Backend | PHPUnit 12 (Pest planned — see Phase 03 note) | Laravel Pint (Larastan planned) |
| Frontend | Vitest + Testing Library, Playwright (E2E) | ESLint, Prettier, `tsc --noEmit` |
| Analyzer | pytest (unit, golden/regression fixtures) | Ruff (lint + format), mypy (strict) |
| Repository | — | markdownlint, yamllint, actionlint, gitleaks |

### Version policy

- The version lines above are the **approved baseline**.
- Exact versions are pinned by lockfiles (`composer.lock`,
  `package-lock.json`, `requirements.txt` with hashes) when each application
  is created (Phases 03, 04, 08). The exact versions selected are recorded in
  each application's README at that time.
- A newer patch or minor release within an approved line is always
  acceptable. Moving to a new major line requires updating this ADR.
- Analyzer dependencies (especially Tree-sitter grammars) are pinned exactly,
  because a grammar change can change analysis output (see ADR-004).

### Amendment — major versions confirmed (2026-10-05, Phase 02)

The audit found that Laravel 13.x and Next.js 16.x had become the current
stable lines. Laravel 12 and Next.js 15 were still supported but no longer
the newest. The project owner chose the **current stable lines**: Laravel
13.x and Next.js 16.x. The table above reflects that decision. Laravel 12 and
Next.js 15 are not used.

Exact versions selected in Phase 02. Lockfiles and image tags are the source
of truth.

| Component | Version | Pinned by |
|---|---|---|
| Laravel framework | 13.34.0 (skeleton `laravel/laravel` 13.10) | `backend/composer.lock` |
| PHP | 8.4.26 (`php:8.4-fpm-trixie`) | `docker/php/Dockerfile` |
| Composer | 2.10.3 | `composer:2.10` image |
| Next.js / React | 16.3.8 / 19.2.8 | `frontend/package-lock.json` |
| Node.js | 22.23 LTS (`node:22-trixie-slim`) | `docker/node/Dockerfile` |
| Python | 3.11.17 (`python:3.11-slim-trixie`) | `docker/python/Dockerfile` |
| FastAPI / Uvicorn | 0.142.2 / 0.54.0 | `analyzer/requirements.txt` (hashes) |
| PostgreSQL | 16.15 (`postgres:16-alpine`) | `docker-compose.yml` |
| Redis | 7.4.11 (`redis:7.4-alpine`) | `docker-compose.yml` |
| Nginx | 1.28.3 (`nginx:1.28-alpine`) | `docker-compose.yml` |
| MinIO | RELEASE.2026-09-22 (Chainguard build, digest-pinned) | `docker-compose.yml` |

PHP 8.4 was chosen over 8.3 because it is the newer, fully supported release
and Laravel 13 supports it. Base image tags pin the major/minor line. Patch
updates arrive with image rebuilds.

### Phase 03 note — backend test and analysis tooling

Pest 5 (which requires PHPUnit 13) and Larastan could not be installed in
the Phase 03 build environment, because its network policy blocks their
GitHub-hosted package downloads. The backend tests are therefore written
for **PHPUnit 12**, Laravel's default runner. Pest runs PHPUnit test
classes unchanged, so adopting it later needs no rewrite. Larastan (static
analysis) is still the plan and should be added in an environment that can
download it. Until then, `make lint-backend` runs Laravel Pint only.

## Consequences

- Three runtimes (PHP, Node, Python) have to be built, tested and deployed.
  This is accepted because each one is the strongest fit for its job.
- Laravel never parses source code. Python never owns business data or the
  database (ADR-005).
- Contracts between components have to be explicit and versioned
  ([internal analyzer contract](../api/internal-analyzer-contract.md),
  [public API conventions](../api/README.md)).

## Alternatives considered

- **Laravel-only, with PHP-based analysis** (e.g. `nikic/php-parser`): rejected.
  It is strong for PHP only and weak for multi-language analysis, and it
  couples the core IP to the web framework.
- **Python-only (Django/FastAPI for everything):** rejected. Laravel gives faster
  delivery of auth, queues, policies and billing, which the product needs.
- **Microservices beyond the three components:** rejected as premature
  (master instruction §32).

## Open questions

None. The major-line question was resolved by the amendment above.
