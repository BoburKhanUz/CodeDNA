# Test Strategy

Phase 22. How CodeDNA is tested, which gate runs what, and the rules for
fixing a bug. What each area covers, and its known gaps, is in the
[QA matrix](qa-matrix.md).

## Principles

- **Deterministic before broad.** Scores, levels, gaps, growth and history
  are pure functions of stored inputs. Tests pin exact values, not ranges.
- **Real dependencies where they decide behavior.** PostgreSQL (locks,
  constraints, triggers), Redis sessions, MinIO, the evaluator sandbox and
  the analyzer's parsers run for real in `make test`. External services
  (GitHub, the AI provider) are replaced by deterministic doubles.
- **Race branches run for real.** Code that handles a concurrent writer
  (unique-violation replays, row locks, lease takeovers) is tested with
  forked processes, each with its own database connection, started at the
  same moment.
- **Failures are part of the contract.** Every failure path has a fixed,
  safe error code and never leaks exception text, paths or secrets.
- **A test that cannot run must fail, not skip,** where its absence would
  hide a broken gate (see [skip guards](#skip-guards)).

## Test layers

| Layer | Tool | Where | Runs in |
|---|---|---|---|
| Backend unit and feature tests | PHPUnit 12 | `backend/tests` | `queue` container (reaches PostgreSQL, Redis, MinIO and the evaluator spool) |
| Backend concurrency tests | PHPUnit + `pcntl_fork` | `*ConcurrencyTest.php`, `ConcurrentUploadTest`, `AccountConcurrencyTest` | Same, with committed rows cleaned up in `tearDown` |
| Frontend component tests | Vitest + Testing Library | `frontend/src/**/*.test.ts(x)` | `frontend` container |
| Analyzer tests | pytest | `analyzer/tests` | `analyzer` container (read-only source) |
| Evaluator protocol and sandbox tests | unittest | `evaluator/tests` | `evaluator` container, as root, with the real slot users |
| Cross-service contracts | PHPUnit, pytest, `scripts/check_contracts.py` | See [contracts](#cross-service-contracts) | Containers and the host |
| Runtime smoke test | `scripts/verify-infra.sh` | Routing, networks, isolation, storage | Host, against the running stack |
| Browser end-to-end | Playwright (Chromium) | Run per phase against the dev stack | Host; not in CI yet (see [limitations](#limitations)) |
| Mutation testing | Scripted source mutations | Per phase, for new and security-critical logic | Host, against the running stack |

## Doubles

| Double | Replaces | Used for |
|---|---|---|
| `Tests\Support\FakeAnalyzer` | The analyzer HTTP service | Signed responses that echo the request's source hash and size, so result binding is exercised |
| `Tests\Support\FakeChallengeEvaluator` | The spool evaluator | Grading without running code |
| `Tests\Support\FakeGitHub` | GitHub REST and archive downloads | Installations, repositories, branches, archives, errors and rate limits |
| `Tests\Support\ScriptedAiProvider` | The AI provider | Valid, invalid, oversized and failing responses |
| `FakePaymentProvider` (`fake`), `Tests\Support\BillingFixtures` | A payment provider | Signed webhooks; paid plans in tests only through a real activation event, never a bypass |
| `FakeFetcher`, `resolver()` (analyzer) | Network download and DNS | URL policy, pinning and download limits |

The real evaluator is exercised by `RealEvaluatorIntegrationTest` (below).
The real analyzer is exercised by the analyzer's own API tests and by the
end-to-end runs.

## Cross-service contracts

| Contract | Checked by |
|---|---|
| Laravel → analyzer request and responses | JSON Schemas in `packages/api-contracts/analyzer/v1`, validated by the analyzer's tests on real responses and by Laravel at runtime |
| HMAC signing | The shared vector `packages/api-contracts/analyzer/v1/hmac-vectors.json`, checked by `HmacSignerTest` (Laravel) and `test_hmac_vectors.py` (analyzer) |
| Laravel → evaluator | `RealEvaluatorIntegrationTest`: Laravel's spool client against the running evaluator; every result passes the client's schema check and the real grader. Every catalog challenge's reference solution passes and its starter code does not |
| Evaluator statuses | `scripts/check_contracts.py`: the statuses the evaluator writes equal the statuses the backend schema accepts |
| Backend → frontend error codes | `scripts/check_contracts.py`: every `ErrorCode` the backend sends is known to the frontend, and the reverse |
| Public HTTP envelope | `RouteContractTest`: over the live route table, every unsupported method answers `405 METHOD_NOT_ALLOWED`, every protected route answers `401` to an anonymous request, unknown paths answer `404`, always as `{error: {code, message, request_id}}` with `request_id` equal to `X-Request-ID` |

## Bug-fix policy

1. Reproduce the defect.
2. Write a regression test that fails for the reason of the defect.
3. Make the smallest fix that makes it pass.
4. Run the affected suites, then the full gate.
5. Record the defect (severity, component, reproduction, root cause, fix,
   test) in the phase's QA report.

A test is never deleted, skipped or weakened to make a suite pass. A
failure is investigated to its root cause; "flaky" is not a root cause.

## Mutation testing

There is no mutation framework for every language in this repository, so
each phase mutates the logic it adds or that guards security: one
deliberate defect per mutant (a removed check, a widened bound, an inverted
condition). The relevant tests run against it, and the source is restored
afterwards. A mutant must be killed. A surviving mutant is classified:

- **missing test:** a test is added, and the mutant is run again;
- **equivalent:** the mutant cannot change behavior, with the reason
  recorded;
- **accepted:** documented as a residual gap.

## Flakiness

- Concurrency tests start their workers with `time_sleep_until()` on a
  shared start time. Under heavy load the workers can run one after another.
  The test then still passes, but without exercising the race (a false
  pass, never a false failure).
- Timing-sensitive frontend tests use fake timers (`vi.useFakeTimers`), never
  real waits.
- The nightly CI job runs every suite three times in a row on a fresh stack.
- Before a phase is committed, the suites are run repeatedly (see the
  phase's QA report).
- An intermittent failure is a defect until proven otherwise. In Phase 22, a
  browser test that failed now and then after logout turned out to be a real
  race: a request still running at logout saved the signed-in session back.

## Test data isolation

- PHPUnit uses a dedicated database, `codedna_test`
  (`scripts/ensure-test-database.sh`). Most tests run inside
  `RefreshDatabase` transactions.
- Concurrency tests must commit their rows. They create their own user and
  project, and delete exactly those rows in `tearDown` (billing rows through
  `BillingFixtures::forget`, before the user). Stored objects use a
  unique key prefix (`phpunit/<ulid>/`) that is deleted afterwards.
- The evaluator integration test uses fresh submission IDs. The spool
  client deletes each result after reading it.
- End-to-end runs use unique emails (`e2e-<phase>-<random>@example.com`) and
  delete their users, projects and stored objects afterwards.

## CI quality gates

`.github/workflows/ci.yml`:

| Job | When | Gates |
|---|---|---|
| `foundation` | Every push and pull request | Required files, links, `.env.example` hygiene, contract parity, Markdown, YAML, workflow, shell and Dockerfile linters, compose config |
| `secrets` | Every push and pull request | Gitleaks over the full history and the working tree |
| `infrastructure` | Every push and pull request | Image build, `make up`, `make verify`, `make test`, ESLint, `tsc`, Pint, Ruff and mypy (analyzer and evaluator), `next build`, dependency audits |
| `regression` | Nightly (03:17 UTC) and on demand | Images built without cache, every suite three times, dependency audits |

Each step is one command; a failing test fails the step, and the job.
`make test` runs the suites with `make`'s default stop-on-error.

### Skip guards

- `RealEvaluatorIntegrationTest` is skipped where no evaluator heartbeat is
  visible. `make test` sets `CODEDNA_REQUIRE_EVALUATOR=1`, which turns that
  skip into a failure.
- The evaluator's sandbox tests need the evaluator container, as root.
  `make test` sets `CODEDNA_REQUIRE_SANDBOX=1`, which makes the suite fail at
  import anywhere else.

## Limitations

- **Browser end-to-end tests are not in CI.** They run against the dev stack
  at the end of each phase (Chromium through Playwright on the host). CI has
  the smoke test, the HTTP contract test and the component tests instead.
- **GitHub Actions runners share a kernel with the evaluator's sandbox.**
  This is the same as in development. The sandbox tests prove the controls
  that are configured, not kernel isolation (see the
  [security hardening](../security/security-hardening.md#residual-risks)
  residual risks).
- **Browser runs against the dev server send twice the requests.** React
  StrictMode runs every effect twice in development. A fast script can
  therefore reach the 120 requests per minute per user API limit; the scripts
  honor `Retry-After`.
- **Dependency audits need the internet.** They run in CI. In an offline or
  proxied environment, `make audit` can fail without a vulnerability.
- **No coverage percentage is published.** No coverage driver (Xdebug or
  PCOV) is installed in the PHP images, and a percentage would not show the
  gaps that matter here. The [QA matrix](qa-matrix.md) lists coverage by
  behavior instead.
