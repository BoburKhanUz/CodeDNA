# V1 Release Readiness (Phase 30)

The evidence and decision for releasing CodeDNA V1, produced by the Phase 30
release validation (V1-RC1 → findings and fixes → V1-RC2). Every result
below comes from a command that was actually run; anything that was not run
says so.

## Contents

- [Decision](#decision)
- [Release candidates](#release-candidates)
- [Environment](#environment)
- [Test results](#test-results)
- [End-to-end journey](#end-to-end-journey)
- [Findings and fixes](#findings-and-fixes)
- [Security review](#security-review)
- [Local AI](#local-ai)
- [Performance and reliability](#performance-and-reliability)
- [Deployment and migrations](#deployment-and-migrations)
- [Release gates](#release-gates)
- [Remaining issues](#remaining-issues)
- [Next steps](#next-steps)

## Decision

| Question | Answer |
|---|---|
| V1.0.0 | **NO-GO.** Mandatory deployment gates are unverified, not failed: CI has not executed for this branch, a clean production image build could not run here, and the gVisor evaluator has not been attested on a production host. |
| Limited beta | **Not approved yet.** Every product, security and data-integrity gate passes on V1-RC2. The beta can start without code changes once gates G1–G3 below pass on the beta host and in CI. |
| Release candidate | V1-RC2, `5a013a1` |

There is no unresolved P0 or P1 defect. Two P1 defects were found and fixed
in Phase 30 (uploads above ~32 MiB failed in production; the UI could not
start an analysis), with regression tests.

## Release candidates

| Candidate | Commit | What changed |
|---|---|---|
| Baseline | `43718b2` | Phase 29, the starting point |
| V1-RC1 | `6c0047f` | Fixes and coverage from the baseline audit (see [Findings](#findings-and-fixes)) |
| V1-RC2 | `5a013a1` | RC1 review: residual risks recorded (`ca14498`); a flaky test found by the RC2 run fixed (`5a013a1`) |

RC1 and RC2 differ in documentation and one test only; the production code
and images are the same. The RC2 run caught one flaky frontend test that
RC1 and the baseline passed by chance (see P3-6).

## Environment

- Cloud sandbox: 4 CPUs, 15 GB RAM, **no GPU**, Docker 29.6.2 with the
  `runc` runtime only (**no gVisor**).
- Outbound HTTPS through an intercepting proxy. Docker builds that download
  packages (pip, npm, Composer, PECL) cannot verify its certificate.
- No credentials for GitHub, GitLab, Bitbucket or a payment provider.
- Development stack: `docker compose` with every service. Production stack:
  `docker-compose.prod.yml` through `scripts/smoke-production.sh`, on
  loopback with throwaway secrets.

## Test results

All on V1-RC2 unless noted. "Doubles" means HTTP fakes or scripted clients;
"local services" means the real PostgreSQL, Redis, MinIO, analyzer and
evaluator containers.

| Area | Command | Result | Kind |
|---|---|---|---|
| Analyzer | `make test` (pytest) | 336 passed | local |
| Backend | `make test` (phpunit, `CODEDNA_REQUIRE_EVALUATOR=1`) | OK, 2061 tests, 21,231 assertions, 0 skipped | local services, provider and AI doubles |
| Frontend | `make test` (vitest) | 571 passed (53 files) | mocked API |
| Evaluator | `make test` (`CODEDNA_REQUIRE_SANDBOX=1`) | 61 tests OK | real sandbox (runc) |
| Static checks | `make check` | pass | — |
| Linters | `make lint-backend lint-analyzer lint-evaluator lint-frontend` | pass | — |
| Frontend build | `make build-frontend` | pass | — |
| Runtime smoke | `make verify` | 121 PASS | local services |
| Production smoke | `scripts/smoke-production.sh` (`SMOKE_BUILD=0`) | 110 PASS, 0 FAIL | production compose, layered images (see G2) |
| Browser journey | Playwright script, real UI and services | 32 PASS, 0 FAIL | local services |
| Secrets | `gitleaks git` | no leaks | — |
| Dependencies | `npm audit --omit=dev`, `composer audit --locked`, `pip-audit` (analyzer runtime and dev) | 0 vulnerabilities each | run from the host (`make audit` cannot reach the registries from containers here) |
| Mutation | 10 targeted mutations of the Phase 30 fixes (plus each fix's test shown failing on the code before it) | all caught after one survivor (archived project could analyze, client side) got a test | — |

Baseline (`43718b2`): the same suites passed (336; 2041 tests, 20,235
assertions; 562; 57), `make check` and `make verify` passed. The baseline
`lint-frontend` failed only because a host-side build had left root-owned
files in `frontend/.next` (environmental; passed after `chown`).

## End-to-end journey

Real UI in Chromium against the development stack (`make up`), with no
mocks: Laravel, PostgreSQL, Redis, MinIO, the analyzer and the evaluator
(runc). The script paces itself like a person; the development server
doubles requests (React StrictMode).

| Step | Result |
|---|---|
| Protected page while signed out → login | PASS |
| Register (signs in) | PASS |
| Duplicate email refused on the form | PASS |
| Sign out invalidates the session (`/me` 200 → 401) | PASS |
| Wrong password refused; correct password signs in | PASS |
| Create project | PASS |
| Upload source v1 | PASS |
| Analyze v1 from the project page, completion shown, no second analysis offered | PASS (fixed in RC1, P1-2) |
| CodeDNA, competencies, skill gaps | PASS |
| Challenge assigned from the skill gaps; wrong attempt not passed; correct attempt passed by the real evaluator | PASS |
| Roadmap created; a step completed | PASS |
| Re-assess: upload and analyze v2 | PASS |
| Growth: COMPARED, 12 observations; learning activity shown as context only | PASS |
| Historical DNA | PASS |
| Phone width (375 px): project, DNA, growth, roadmap without horizontal scroll | PASS (fixed in RC1, P3-4) |
| No uncaught page errors, no 5xx | PASS |

Repository connection was not part of the journey: no provider account is
available (see G4).

## Findings and fixes

| ID | Severity | Finding | Fix | Regression test |
|---|---|---|---|---|
| P1-1 | P1 | Every upload above ~32 MiB failed with HTTP 500 in the production stack (Nginx buffered bodies in a 32 MiB tmpfs); PHP upload temporaries shared a 160 MiB memory tmpfs | Request bodies and upload temporaries on disk volumes (`8254d98`) | Production smoke: one and two concurrent 45 MiB uploads (reproduced 500 before, 201 after); `check_production.py` requires the volumes |
| P1-2 | P1 | The UI could not start an analysis, so a user could not reach CodeDNA, skill gaps, challenges, roadmap or growth | Analyses card on the project page (`dbeac45`) | `project-analyses.test.tsx`, project page tests, browser journey |
| P2-1 | P2 | Challenge code could read hidden test inputs (stack frames, `gc`) and return them as a visible case's value, which feedback shows; and write result records for other cases to fd 1 | One sandbox process per case with a shared time budget (`0c8fe01`) | 4 sandbox tests (3 fail on the old code) |
| P2-2 | P2 | `/api/v1/health` answered 500 instead of its 503 when Redis was down (the throttle failed first) | Health outside the Redis-backed throttle; Nginx still limits it (`6dc0933`) | `HealthTest` with the production cache store (fails before the fix) |
| P3-1 | P3 | The session check of every page render shared the API budget with polling; once spent, navigation showed a misleading error page | Own `session` limiter for `GET /me` (`6dc0933`) | `MeTest` (fails before the fix) |
| P3-2 | P3 | Insight listing validated before authorizing, so another user's project answered 422 vs 404 (existence oracle, against the Phase 21 rule) | Authorize first (`6dc0933`) | `InsightApiTest` (fails before the fix) |
| P3-3 | P3 | No test that a failed AI assessment is refunded; the cross-user matrix lacked AI and provider routes | Tests added (`6c0047f`) | — |
| P3-4 | P3 | 2 px horizontal overflow on the project page at 375 px (file input) | `w-full min-w-0` (`dbeac45`) | Browser journey |
| P3-5 | P3 | Stale UI copy: "Importing from repositories is not available yet" | Copy updated (`dbeac45`) | — |
| P3-6 | P3 | Flaky Phase 17 roadmap test (found by the RC2 run) | Scoped to the deterministic content (`5a013a1`) | 8/8 and full-suite runs |

## Security review

Three read-only code audits (authorization and IDOR across every route;
uploads, analyzer, evaluator and provider imports; billing, sessions and
frontend rendering), followed by runtime reproduction of every candidate
before acting on it. **No P0 or P1 security defect was found.**

- **Tenant isolation:** every `/api/v1` route is owner- or
  membership-scoped; nested resources use scoped bindings or explicit
  `project_id` filters. The cross-user matrix (97 tests) and the team
  matrix (144 organization tests) now include AI and provider routes.
- **Uploads and archives:** server-side ZIP inspection (traversal,
  symlinks, special files, bombs, CRC, encryption); the analyzer re-checks
  every limit, never follows symlinks and never executes code.
- **SSRF:** analyzer URLs are allow-listed, SigV4 pre-signed, resolved to
  global addresses and connected to the pinned IP; redirects are refused.
  Provider imports allow exactly one redirect to configured archive origins.
- **Evaluator:** fixed command, rlimits, unprivileged slot users, no
  network, gVisor required and attested in production (fails closed). P2-1
  fixed.
- **Billing:** atomic, transactional quota consumption with row locks;
  refunds at most once (unique ledger index, immutable ledger); signed,
  replay-protected webhooks; no client-controlled plan changes.
- **Sessions and HTTP:** session regeneration, logout invalidation, rate
  limits, CSRF through Sanctum, strict CORS, TLS-only cookies, HSTS and a
  nonce CSP in production; no `dangerouslySetInnerHTML` anywhere; API IDs
  validated before building paths.
- **AI:** prompt injection, unknown references, unsupported numbers,
  contradicting growth or outcome claims, locked steps, markup, links,
  score fields and malformed output are rejected (evaluation set, below);
  AI never writes deterministic tables (score-invariance tests); no
  external fallback; remote endpoints need https and an explicit opt-in.
- **Secrets:** gitleaks clean; OAuth callback codes are kept out of the
  Nginx and Next.js logs (`make verify`).

Accepted P3 residual risks are listed in
[security hardening](../security/security-hardening.md#residual-risks).
The audit was not exhaustive: live provider behavior and gVisor were not
available, and no third-party penetration test was performed.

## Local AI

AI is optional and disabled by default; every deterministic page is
complete without it (verified by the journey with AI disabled).

| Item | Result |
|---|---|
| Validator correctness | `InsightEvalSetTest`: 18 of 18 cases get exactly the expected decision |
| Score invariance | Accepted, rejected and retried insights leave every deterministic row unchanged (`InsightGenerationTest`) |
| Real model | `qwen2.5:0.5b` (397 MB, Ollama 0.12.6), CPU only, 4 cores: `php artisan ai:eval` accepted **6 of 18** (33%); 16–50 s per case; 655–886 input and 265–584 output tokens |
| Rejection reasons | 10 `schema` (the model repeated an evidence ID within one claim), 1 `direction_contradicts_evidence`, 1 `direction_without_observation`; no runtime errors. The sampled growth answer also called an UNCHANGED metric "improved", which the evidence rules catch next |
| `qwen2.5-coder:7b` on an RTX 3060 6 GB | **NOT RUN** (no GPU here) |
| GPU checks (driver, Docker GPU access, CPU/GPU split, VRAM) | **NOT RUN** |

Interpretation: the validator fails closed as designed; the 0.5B model is
not useful enough for user-facing explanations. Model quality on the target
GPU is unknown until `php artisan ai:eval` runs there.

## Performance and reliability

| Check | Result |
|---|---|
| In-process API benchmark (`make benchmark-api`, small scale, 30 iterations, development container) | 61 endpoints, all 200; p95 between 2.6 and 31 ms for most; heaviest: long history p95 ≈ 85 ms, large-organization analytics p95 67 ms. Not production capacity |
| HTTP load test through Nginx (`make loadtest`) | NOT RUN in Phase 30 (Phase 26 results in [load testing](../performance/load-testing.md)) |
| Worker killed mid-analysis (`docker compose kill queue` while RUNNING) | The reserved job was redelivered after `retry_after` and the run SUCCEEDED (≈ 6 minutes) |
| Redis stopped | Health 503 with `redis: fail` (500 before P2-2); 200 within 2 s of restart |
| Upload capacity (production) | 45 MiB alone and two concurrent: 201 |
| AI disabled | `ai:status` contacts nothing and exits 0; AI requests refused; no deterministic page depends on AI |

## Deployment and migrations

| Check | Result |
|---|---|
| Fresh install (scratch database) | 23 migrations applied |
| Roll back and re-apply the newest migration (`ai_insights`) | pass |
| Roll back everything (`migrate:reset`) and reinstall | every `down()` ran (only `migrations` left); 23 re-applied; a further run was a no-op |
| Upgrade in place | The development database has been upgraded through every phase with its data |
| Backup and restore drill (documented commands) | `pg_dump --format=custom` of the development data restored into a scratch database with `--exit-on-error`; row counts equal for users, projects, snapshots, analyses, DNA, growth, submissions, insights and the billing ledger |
| AI default changes (Phase 29) | Verified in code: `AI_ENABLED` defaults to false; `openai_compatible` installations relying on the old default URL must set `AI_BASE_URL` and `AI_MODEL`; a remote production endpoint is refused without `AI_ALLOW_REMOTE_ENDPOINT=true` (tests and production smoke) |
| Clean production image build | **BLOCKED here** (proxy TLS inside builds); see G2 |
| Ollama off unless `--profile local-ai`; AI worker hardened; no new published ports | production smoke |

## Release gates

| Gate | Status |
|---|---|
| No unresolved P0/P1 | PASS |
| Critical journey (register → growth) | PASS |
| Authentication, authorization, tenant isolation | PASS |
| Deterministic scoring and history integrity | PASS (score-invariance tests, migrations, journey) |
| AI safety and score invariance | PASS |
| AI disabled and unavailable behavior | PASS |
| Uploads and analysis reliability | PASS |
| Migrations, rollback, backup restore | PASS |
| Production smoke test | PASS (layered images) |
| Dependency audits and secret scan | PASS |
| **G1** CI executes the suites | **BLOCKED**: every recent run of `.github/workflows/ci.yml` on this branch failed within seconds without a runner (`runner_id` 0, no logs); likely the Actions account or billing |
| **G2** Clean production image build and smoke from scratch | **BLOCKED** here; runs in CI (`make prod-build`, `make prod-smoke`) once G1 is fixed |
| **G3** gVisor evaluator attested on the production host | **NOT RUN** (`make prod-evaluator-attest`); alternatively start with challenges disabled (`CHALLENGE_EVALUATOR=none` fails closed) |
| **G4** Live GitHub App, GitLab and Bitbucket OAuth and imports | **NOT RUN** (no credentials); Bitbucket archive redirect hosts still unverified live |
| **G5** Real model on the target GPU | **NOT RUN**; AI stays disabled until it passes |
| **G6** HTTP load test on the release images | **NOT RUN** in Phase 30 |

## Remaining issues

| Issue | Severity | User impact | Next action | Blocks beta | Blocks V1.0.0 |
|---|---|---|---|---|---|
| G1 CI not executing | release process | No independent, repeatable verification | Fix the Actions account or runners; re-run CI on RC2 | yes | yes |
| G2 Clean image build unverified | deployment | A release could fail to build | `make prod-build && make prod-smoke` in CI or a networked host | yes | yes |
| G3 gVisor not attested | security | Challenges cannot run (fail closed) until attested | `make prod-evaluator-attest` on the host | yes, or disable challenges | yes |
| G4 Providers not verified live | integration | Imports could fail against real providers | One test account per provider; or launch the beta with uploads only | no, if providers stay off | yes |
| G5 Model quality unknown on the GPU | AI usefulness | None while AI stays disabled | `ai:eval` with `qwen2.5-coder:7b` on the RTX 3060 | no (AI off) | no, if AI ships disabled |
| G6 HTTP load test not repeated | performance | Capacity unknown for the new images | `make benchmark-seed loadtest` on the release images | no | yes |
| Validator over-strictness (mixed improved/unchanged sentence) | P3 | Some accurate explanations discarded | Rule refinement with tests | no | no |
| Accepted P3 residual risks | P3 | See [security hardening](../security/security-hardening.md#residual-risks) | As planned there | no | no |
| Limitations: Git LFS and submodules not imported; one self-managed GitLab; no Bitbucket Data Center; rotating `APP_KEY` needs `APP_PREVIOUS_KEYS` or reconnects | documented | As documented in [GitLab](../integrations/gitlab.md), [Bitbucket Cloud](../integrations/bitbucket-cloud.md) and [OAuth setup](../integrations/oauth-setup.md) | — | no | no |

## Next steps

1. Fix GitHub Actions for the repository and re-run CI on RC2 (G1, G2).
2. On the beta host: `make prod-build`, `make prod-smoke`,
   `make prod-evaluator-attest` (G2, G3).
3. Decide the beta scope: uploads only, or verify one account per provider
   first (G4).
4. Keep `AI_ENABLED=false` for the beta; run `php artisan ai:eval` with
   `qwen2.5-coder:7b` on the RTX 3060 and enable AI only if the accepted
   answers are useful (G5).
5. Repeat the HTTP load test on the release images (G6).
6. Then re-run this checklist and decide V1.0.0.
