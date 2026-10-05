# ADR-001: Technology Stack

- **Status:** Accepted
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
| Frontend | Next.js (App Router), React, TypeScript | Next.js **15.x** | Tailwind CSS + shadcn/ui |
| Backend | Laravel, PHP | Laravel **12.x**, PHP **8.3+** | REST API under `/api/v1` |
| Analyzer | Python, FastAPI | Python **3.11**, FastAPI current stable | Internal-only HTTP service |
| Parsing | Tree-sitter via `py-tree-sitter` | current stable | See ADR-002 |
| Database | PostgreSQL | **16** | Owned exclusively by Laravel |
| Queue / cache / sessions | Redis | **7** | Laravel queues only (see ADR-005) |
| Object storage | S3-compatible API | — | Cloudflare R2 in production (ADR-003) |
| Runtime packaging | Docker, Docker Compose, Nginx | — | Phase 02 |
| CI | GitHub Actions | — | |

### Testing and quality tooling

| Component | Tests | Lint / format / static analysis |
|---|---|---|
| Backend | Pest (on PHPUnit) | Laravel Pint, Larastan |
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

### Version check performed on 2026-10-05

When this ADR was written, the newest stable lines were **Laravel 13.x**
(latest 12.x: 12.69.3) and **Next.js 16.x** (latest 15.x: 15.5.27,
published under npm's `backport` tag). Laravel 12 and Next.js 15 are still
supported but are no longer the newest major lines. The project owner
approved Laravel 12 and Next.js 15. This ADR records that decision and
leaves the major-line question open until Phase 03/04 starts. See
“Open questions”.

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

- **Major lines for Phase 03/04:** stay on Laravel 12 / Next.js 15 as approved,
  or start on Laravel 13 / Next.js 16? Recommendation: start on the newest
  stable lines, so the project doesn't begin with an immediate major upgrade.
  This needs explicit owner confirmation before Phase 03.
