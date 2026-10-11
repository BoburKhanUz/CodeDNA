# V1 Release Readiness

The evidence and decision for releasing CodeDNA V1.0.0. Every result below
comes from a command that was actually run; anything that was not run says
so. The Phase 30 validation that preceded it (V1-RC1 and V1-RC2) is kept as
a [record](#phase-30-record-v1-rc2) at the end.

## Contents

- [Decision](#decision)
- [Scope](#scope)
- [Candidate](#candidate)
- [Release gates](#release-gates)
- [Evidence](#evidence)
- [Security review](#security-review)
- [Known limitations](#known-limitations)
- [Outstanding issues](#outstanding-issues)
- [Before go-live](#before-go-live)
- [Phase 30 record (V1-RC2)](#phase-30-record-v1-rc2)

## Decision

| Question | Answer |
|---|---|
| V1.0.0 | **NO-GO** until the staging load test runs on an authorized staging target and the preflight passes on the production host. Every gate that could run here passes; none failed |
| Release candidate | `29cfd40` plus the preflight and documentation commits that follow it (no application code changes after `29cfd40`) |

There is no known unresolved P0 or P1 defect.

## Scope

V1.0.0 is uploads-only and deterministic:

- **Included:** ZIP upload, analysis, CodeDNA, competencies, skill gaps,
  learning roadmap, growth, historical DNA, accounts, projects, teams and
  plans as built.
- **Excluded:** repository import from GitHub, GitLab and Bitbucket Cloud
  (not verified against live providers). With no provider configured, the
  UI offers upload only and the provider APIs answer `503`.
- **Off:** `AI_ENABLED=false`. AI never changes a score in any
  configuration.
- **Off:** `CHALLENGE_ENABLED=false`, `CHALLENGE_EVALUATOR=none`. The
  evaluator is in the `challenges` Compose profile and is not started.
  Challenge requests answer `409 CHALLENGES_DISABLED`; boot validation
  refuses `CHALLENGE_ENABLED=true` with `none`.

`make prod-preflight`
([deployment step 14a](../operations/production-deployment.md#14a-run-the-release-preflight))
refuses a production configuration outside this scope.

## Candidate

| Commit | Change |
|---|---|
| `fc784d8` | Evaluator: the wall clock, not `RLIMIT_CPU`, ends a timed-out run (fixed a CI race) |
| `8c77ac8` | V1.0.0 ships with challenges off; evaluator only in the `challenges` profile; boot validation, static checks and smoke checks for it |
| `29cfd40` | Frontend: repository import offered only for providers the server reports as configured (fails closed) |
| `d0ece8d` | `make prod-preflight` (`scripts/preflight-production.sh`) |
| following | Documentation: README scope, this report, gVisor requirement |

## Release gates

| Gate | Status | Evidence |
|---|---|---|
| CI and regression | FAIL | Push CI green: run [#36](https://github.com/BoburKhanUz/CodeDNA/actions/runs/38102598270) on `29cfd40`, run [#37](https://github.com/BoburKhanUz/CodeDNA/actions/runs/38104170546) on `ae40912`. Nightly regression [#38](https://github.com/BoburKhanUz/CodeDNA/actions/runs/38130158587) on `ae40912` failed in its second of three repeated runs: a race in a GitHub panel test that predates this release (the initial branch request counted as a poll; reproduced under CPU load, fixed in the test). PASS once a nightly passes on the fixed commit; previous nightly [#35](https://github.com/BoburKhanUz/CodeDNA/actions/runs/38042546506) on `fc784d8` passed |
| Production images and smoke test | PASS | CI #36 built fresh production images and ran `scripts/smoke-production.sh`: 113 PASS, 0 FAIL, including "no evaluator container", "challenges off" and the boot refusal of `CHALLENGE_ENABLED=true` |
| Upload-based end-to-end journey | PASS | API 37/37, browser 19/19, restart 3/3 ([evidence](#evidence)) |
| Security review | PASS | [Security review](#security-review); no P0/P1 finding. No third-party penetration test |
| Production configuration preflight | BLOCKED | The procedure is built and tested (positive and negative cases), but it has not run against the real production environment file and host (no access) |
| Staging load test | BLOCKED | No authorized staging target. The harness (`make loadtest`) is development-stack only; targets: reads p95 < 300 ms, zero 5xx |
| Documentation | PASS | README scope, deployment step 14a, gVisor requirement, this report; `make check` clean |
| Backup and restore | PASS | Documented drill on local data: PostgreSQL dump restored with `--exit-on-error`, equal row counts; object storage mirrored out and back, 0 differences. Repeat on the production host before go-live |

## Evidence

### CI run #36 (`29cfd40`)

| Area | Result |
|---|---|
| Analyzer (pytest) | 336 passed |
| Backend (PHPUnit) | OK, 2065 tests, 21,258 assertions |
| Frontend (Vitest) | 579 passed (54 files); production build compiled |
| Evaluator | 65 tests OK |
| Linters | Pint (708 files), ruff, mypy, ESLint, TypeScript clean |
| Runtime smoke (`make verify`) | 121 PASS, 0 FAIL |
| Production smoke | 113 PASS, 0 FAIL on images built by the run |
| Dependency audits, secret scan | no vulnerabilities, no leaks |

### End-to-end journey

Development stack (all real services: Laravel, PostgreSQL, Redis, MinIO,
analyzer) with `AI_ENABLED=false CHALLENGE_ENABLED=false
CHALLENGE_EVALUATOR=none`, through Nginx.

- **API (37/37):**
  - Registration of two users, an upload project.
  - Six unsafe archives refused with controlled `4xx` codes and nothing
    stored: path traversal, absolute path, symlink, a 250 MiB single-file
    bomb, an aggregate bomb, and a non-ZIP file.
  - Two uploads and analyses succeeded. DNA, competencies, skill gaps,
    roadmap (201), growth and timeline worked, and analysis history paged.
  - Another user's project answered 404; anonymous requests 401.
  - Excluded features answered controlled errors: GitHub
    `503 GITHUB_NOT_CONFIGURED`, GitLab `503 PROVIDER_NOT_CONFIGURED`,
    challenges `409 CHALLENGES_DISABLED`, AI `409 AI_ASSESSMENT_DISABLED`.
  - The upload rate limit returned 429 with `Retry-After` and recovered.
  - Logout returned 204, then the session answered 401.
- **Browser, Chromium (19/19):**
  - Register, then create a project from a form with no Repository option,
    and see no provider links on the project.
  - Upload and analyze in the browser; a refresh keeps the result.
  - DNA, competencies, skill gaps, roadmap, growth and history pages render.
  - An unknown project shows a not-found state, and sign-out redirects to
    `/login`.
  - No 5xx and no console errors.
- **Restart (3/3):** after `docker compose down` (volumes kept) and `up`,
  login works, both snapshots persist and the DNA score is unchanged.
- **Logs:** 723,988 lines scanned. None contained an environment secret or
  the test password, a bearer token, session or XSRF value, a PEM block, or
  uploaded source text.

### Preflight

`scripts/preflight-production.sh` against a production-compose test
environment:

- The static checks pass for the V1.0.0 configuration.
- Each of these fails as expected: `AI_ENABLED=true`,
  `CHALLENGE_ENABLED=true`, `CHALLENGE_EVALUATOR=spool`,
  `COMPOSE_PROFILES=challenges`, and a world-readable environment file.
- With the data services up and migrations applied, the runtime part passes
  (11 PASS). It fails with Redis stopped.
- It printed no secret values.

### Backup and restore drill

[Restore drill](../operations/backup-and-restore.md#restore-drill) on the
development data:

- **PostgreSQL:** `pg_dump --format=custom`, checksum verified, restored into
  a scratch `postgres:16-alpine` with `--exit-on-error`. Row counts were equal
  for users (118), projects (118), source snapshots (124), analysis runs
  (110), DNA snapshots (110) and migrations (23). It took 12 s.
- **Object storage:** `mc mirror` of the source bucket out (147 objects) and
  back into a scratch bucket (147 objects), with 0 differences reported by
  `mc diff`; the scratch bucket was then removed.

## Security review

**No new P0 or P1 finding.**

- **Scope enforcement.** Challenges and AI are off, and their endpoints
  refuse before anything is recorded, queued or written to the spool
  (`ChallengeEvaluatorBindingTest`, production smoke). The evaluator is not
  part of the default production stack. Boot validation, `check_production.py`
  and the preflight each refuse a configuration that would enable them.
- **Providers.** With none configured, the UI does not advertise import,
  the hook fails closed on any error, and the API answers `503`.
- **Uploads.** Traversal, absolute paths, symlinks, bombs and non-ZIP files
  were refused over HTTP. Rate limiting held.
- **Authentication and tenant isolation.** Cross-user 404, anonymous 401
  and logout invalidation were checked end to end, in addition to the Phase
  30 matrices.
- **Secrets.** CI secret scan and dependency audits are clean. No secret,
  token or source text appeared in the logs of the full journey. The
  preflight prints no secrets.

Not done: third-party penetration test, live provider review (excluded from
V1.0.0), gVisor on a real host (challenges off). Accepted P3 residual risks
are in [security hardening](../security/security-hardening.md#residual-risks).

## Known limitations

- **No repository import.** Repository import is excluded. Sources are
  uploaded as ZIP files: at most 50 MiB compressed, 200 MiB uncompressed,
  20,000 files and 25 MiB per file.
- **No AI explanations or coding challenges.** Both are off.
- **The UI still advertises coding challenges.** Roadmaps contain a
  `CHALLENGE` step and the UI still links to Coding Challenges; starting
  one shows `409 CHALLENGES_DISABLED`. Roadmap steps, including the
  `CHALLENGE` step, can be completed in order (normal prerequisite rule
  only). Tracked as P2,
  [BoburKhanUz/CodeDNA#1](https://github.com/BoburKhanUz/CodeDNA/issues/1).
- **Capacity unknown.** It is not measured on the release images or a
  staging host.
- **Delayed nightly CI.** GitHub starts the scheduled nightly regression
  hours after its cron time.

## Outstanding issues

| Issue | Severity | Blocks V1.0.0 | Next action |
|---|---|---|---|
| Staging load test not run | release gate | yes | Authorize a staging target; run `make benchmark-seed loadtest` against it |
| Preflight not run on the production host | release gate | yes | `make prod-preflight` on the host (deployment step 14a) |
| Challenges still advertised while disabled ([BoburKhanUz/CodeDNA#1](https://github.com/BoburKhanUz/CodeDNA/issues/1)) | P2 | no | Hide challenge links when disabled |
| gVisor not attested on a production-equivalent host | security | no (challenges off) | `make prod-evaluator-attest` before challenges are ever enabled |
| Live provider imports unverified | integration | no (excluded) | One test account per provider before enabling |
| Model quality on the target GPU unknown | AI | no (AI off) | `php artisan ai:eval` on the GPU before enabling AI |

## Before go-live

1. Run the load test on an authorized staging target with the release
   images. It must show reads p95 < 300 ms and zero 5xx.
2. On the production host:
   - `make prod-build` and `make prod-smoke`;
   - the migration job;
   - `make prod-preflight`, which must end with `Preflight passed.`;
   - a backup and a restore drill.
3. Then re-decide V1.0.0.

## Phase 30 record (V1-RC2)

The validation of V1-RC2 (`5a013a1`) before the V1.0.0 scope decision. It
covered challenges and AI, which V1.0.0 ships disabled. Its open gates
(G1–G6) are superseded by the [release gates](#release-gates) above: CI runs
(G1), clean images build and pass the smoke test in CI (G2), and G3–G5 are
out of scope while challenges, providers and AI are off. G6 is the staging
load test gate.

### Environment

- Cloud sandbox: 4 CPUs, 15 GB RAM, **no GPU**, Docker 29.6.2 with the
  `runc` runtime only (**no gVisor**).
- Outbound HTTPS through an intercepting proxy. Docker builds that download
  packages (pip, npm, Composer, PECL) cannot verify its certificate.
- No credentials for GitHub, GitLab, Bitbucket or a payment provider.
- Development stack: `docker compose` with every service. Production stack:
  `docker-compose.prod.yml` through `scripts/smoke-production.sh`, on
  loopback with throwaway secrets.

### Test results

All on V1-RC2 (`5a013a1`) unless noted. "Doubles" means HTTP fakes or scripted clients;
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

### End-to-end journey (Phase 30)

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

### Findings and fixes

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

### Security review (Phase 30)

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

### Local AI

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

### Performance and reliability

| Check | Result |
|---|---|
| In-process API benchmark (`make benchmark-api`, small scale, 30 iterations, development container) | 61 endpoints, all 200; p95 between 2.6 and 31 ms for most; heaviest: long history p95 ≈ 85 ms, large-organization analytics p95 67 ms. Not production capacity |
| HTTP load test through Nginx (`make loadtest`) | NOT RUN in Phase 30 (Phase 26 results in [load testing](../performance/load-testing.md)) |
| Worker killed mid-analysis (`docker compose kill queue` while RUNNING) | The reserved job was redelivered after `retry_after` and the run SUCCEEDED (≈ 6 minutes) |
| Redis stopped | Health 503 with `redis: fail` (500 before P2-2); 200 within 2 s of restart |
| Upload capacity (production) | 45 MiB alone and two concurrent: 201 |
| AI disabled | `ai:status` contacts nothing and exits 0; AI requests refused; no deterministic page depends on AI |

### Deployment and migrations

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
