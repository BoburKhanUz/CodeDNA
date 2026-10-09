# Architecture Overview

CodeDNA is a developer intelligence platform. It analyzes real source code
deterministically, turns it into a versioned "Developer DNA" profile, and
later uses that evidence for competencies, skill gaps, learning and growth
tracking. See [product vision](../product/vision.md) and
[MVP definition](../product/mvp.md).

**Current status:** Phase 14 (skill gap analysis) complete. The
local environment runs every component. Users can register, sign in and
sign out, edit their developer profile, change their password, create and
archive projects, and upload ZIP archives that become immutable, versioned
source snapshots in S3-compatible storage. The internal analyzer can
authenticate a signed request, download a snapshot from an allow-listed
pre-signed URL, extract it safely, discover files and languages, and return
the Phase 08 foundation result or, on request, parse ten languages
with Tree-sitter into IR 1.1 and return a versioned, hashed static-analysis
result with deterministic metrics and structural
findings ([metrics-v1.md](metrics-v1.md)). The API starts analyses of
snapshots; a Redis queue worker calls the analyzer, verifies and stores the
result as an analysis run's result ([data-flow.md](data-flow.md#analysis-pipeline-phase-10)).
Each successful static-analysis result is then scored deterministically
(scoring version 1.0.0: dimensions COMPLEXITY, STRUCTURE and CODE_HYGIENE,
an overall score and a data-quality value) into an immutable DNA snapshot
([dna-scoring-v1.md](dna-scoring-v1.md)). A read-only DNA API and the
CodeDNA dashboard present those snapshots per project
([frontend.md](frontend.md#codedna-dashboard-appprojectsprojectdna)); there is
an Analyses card on the project page that starts the static analysis of the
newest snapshot (Phase 30). Each DNA
snapshot is then turned into a deterministic, versioned competency matrix
(complexity management, function design, type structure, code hygiene;
[competency-matrix-v1.md](competency-matrix-v1.md)), readable through the
API and on the Competency Matrix page, and compared with a versioned
engineering target into per-competency skill gaps with priorities
([skill-gap-v1.md](skill-gap-v1.md)). On request, and only when enabled, an
AI model writes a non-authoritative, evidence-referenced interpretation of
a skill gap analysis; it never changes any score
([ai-assessment-v1.md](ai-assessment-v1.md),
[ADR-007](../decisions/ADR-007-ai-interpretation.md)). Coding challenges
selected deterministically from a project's skill gaps let developers
practice. Submissions run only in a network-less sandbox, and challenge
completion never changes CodeDNA
([coding-challenges-v1.md](coding-challenges-v1.md),
[ADR-008](../decisions/ADR-008-coding-challenges.md)). A learning roadmap,
generated deterministically from the newest skill gap analysis, sets the
development focus and ordered learning steps. Completing steps is learning
progress, never an assessment
([learning-roadmap-v1.md](learning-roadmap-v1.md),
[ADR-009](../decisions/ADR-009-learning-roadmap.md)). Growth tracking
compares each new code assessment with the immediately preceding comparable
one, from stored deterministic snapshots only; learning activity is shown as
context and is never growth evidence
([growth-tracking-v1.md](growth-tracking-v1.md),
[ADR-010](../decisions/ADR-010-growth-tracking.md)). Source can be imported
from GitHub through a read-only GitHub App. Each import becomes an ordinary
immutable source snapshot, and repository code is never executed
([github-integration-v1.md](github-integration-v1.md),
[ADR-011](../decisions/ADR-011-github-integration.md)). Historical DNA shows
every assessment of a project as it was recorded. It is a read model over
the existing immutable snapshots: growth says what changed, history shows
what each assessment looked like
([historical-dna-v1.md](historical-dna-v1.md),
[ADR-012](../decisions/ADR-012-historical-dna.md)). Everything below is
the target architecture.

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
| Frontend | `frontend/` | UI only: dashboard, projects, analyses, DNA views | [frontend.md](frontend.md) |
| Backend | `backend/` | Auth, domain, persistence, orchestration, authorization, business rules | [backend.md](backend.md), [data-model.md](data-model.md) |
| Analyzer | `analyzer/` | Parsing, IR, metrics, findings | [analyzer.md](analyzer.md) |
| AI interpretation | `backend/` (Phase 15) | Explaining stored deterministic results; never producing scores | [ai-assessment-v1.md](ai-assessment-v1.md), [ADR-007](../decisions/ADR-007-ai-interpretation.md) |
| Challenge evaluator | `evaluator/` (Phase 16) | Running submitted challenge code in a network-less sandbox; observations only | [challenge-evaluator.md](challenge-evaluator.md), [ADR-008](../decisions/ADR-008-coding-challenges.md) |
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
6. **Submitted challenge code runs only in the evaluator**, which has no
   network, no secrets and no data. Challenge completion ≠ CodeDNA
   improvement: no evaluation result changes a score, competency, skill gap
   or snapshot ([ADR-008](../decisions/ADR-008-coding-challenges.md)).
7. **Roadmap ≠ assessment.** Completing learning steps never changes a
   score, competency, gap or priority; only a new analysis of new code
   does ([ADR-009](../decisions/ADR-009-learning-roadmap.md)).
8. **Growth is observed, never scored.** Growth compares stored assessments
   of the same project measured with the same versions. Growth ≠ learning,
   challenges, AI or self-report; no baseline ≠ zero; incomparable ≠
   regression; insufficient evidence ≠ regression
   ([ADR-010](../decisions/ADR-010-growth-tracking.md)).
9. **GitHub is a source provider; SourceSnapshot is the source of truth.**
   Imports go through the upload checks into immutable snapshots, and are
   analyzed by the same pipeline. Disconnecting GitHub never deletes
   historical CodeDNA data, and tokens never leave the server
   ([ADR-011](../decisions/ADR-011-github-integration.md)).
10. **History is read, never rewritten.** Historical DNA reads the immutable
    snapshots and stores nothing. It never recalculates a value, never
    replaces a past state with a current one, and never compares or
    connects assessments measured with different versions
    ([ADR-012](../decisions/ADR-012-historical-dna.md)).

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
| [ADR-007](../decisions/ADR-007-ai-interpretation.md) | AI interpretation: non-authoritative, provider-neutral, evidence-bound |
| [ADR-008](../decisions/ADR-008-coding-challenges.md) | Coding challenges: a practice layer with an isolated evaluator |
| [ADR-009](../decisions/ADR-009-learning-roadmap.md) | Learning roadmap: a deterministic planning layer, generated on request |
| [ADR-010](../decisions/ADR-010-growth-tracking.md) | Growth tracking: an observation layer over deterministic assessments |
| [ADR-011](../decisions/ADR-011-github-integration.md) | GitHub integration: a GitHub App as a read-only source provider |
| [ADR-012](../decisions/ADR-012-historical-dna.md) | Historical DNA: a read model over immutable snapshots |

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
| 03 | Laravel backend foundation | Done |
| 04 | Next.js frontend foundation | Done |
| 05 | Database and domain model | Done |
| 06 | Authentication and developer profile | Done |
| 07 | Projects and repository management (ZIP upload) | Done |
| 08 | Python analyzer foundation (IR frozen first) | Done |
| 09 | AST and static analysis (PHP → Python → JS → TS) | Done (all ten languages) |
| 10 | Analysis queue and pipeline | Done |
| 11 | CodeDNA scoring engine | Done (scoring version 1.0.0, in Laravel; [dna-scoring-v1.md](dna-scoring-v1.md)) |
| 12 | DNA dashboard (**MVP complete**) | Done (per-project dashboard and read-only DNA API) |
| 13 | Competency matrix | Done (competency version 1.0.0; [competency-matrix-v1.md](competency-matrix-v1.md)) |
| 14 | Skill gap analysis | Done (skill gap version 1.0.0, ENGINEERING_STANDARD; [skill-gap-v1.md](skill-gap-v1.md)) |
| 15 | AI assessment and interpretation | Done (assessment version 1.0.0, non-authoritative, on request; [ai-assessment-v1.md](ai-assessment-v1.md)) |
| 16 | Coding challenges | Done (catalog 1.0.0, Python, deterministic selection, sandboxed evaluator; [coding-challenges-v1.md](coding-challenges-v1.md)) |
| 17 | Learning roadmap | Done (roadmap catalog and rules 1.0.0, deterministic, on request; [learning-roadmap-v1.md](learning-roadmap-v1.md)) |
| 18 | Growth tracking | Done (growth rules 1.0.0, immediate previous comparable assessment, read-only; [growth-tracking-v1.md](growth-tracking-v1.md)) |
| 19 | GitHub integration | Done (GitHub App, read-only, imports into source snapshots; [github-integration-v1.md](github-integration-v1.md)) |
| 20 | Historical DNA | Done (read model, no new persistence, version segments, comparison through growth rules; [historical-dna-v1.md](historical-dna-v1.md)) |
| 21 | Security hardening | Done (audit, network segmentation, proxy and rate-limit fixes, sandbox and boundary hardening; [threat model](../security/threat-model.md), [hardening](../security/security-hardening.md)) |
| 22 | QA and regression | Done ([test strategy](../testing/test-strategy.md), [QA matrix](../testing/qa-matrix.md)) |
| 23 | Billing / SaaS foundation | Done (plan catalog 1.0.0, FREE fallback, entitlements, quotas, usage ledger, subscription state machine, provider-neutral webhooks; no real provider; [billing architecture](../billing/billing-architecture.md)) |
| 24 | Teams / B2B foundation | Done (organizations, roles, hashed single-use invitations, team projects, organization billing subject and seats, audit log, read-only team analytics; [teams architecture](../teams/teams-architecture.md)) |
| 25 | Production | — |
