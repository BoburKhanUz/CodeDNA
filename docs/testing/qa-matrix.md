# QA Matrix

Phase 22. What is tested, by behavior, and what is not. How the suites run
and gate changes is in the [test strategy](test-strategy.md).

Legend: **✓** tested · **◐** partly tested (see the note) · **—** not
tested.

## Coverage by area

| Area | Unit | API / feature | Concurrency | Failure paths | Contract | Security | Browser E2E |
|---|---|---|---|---|---|---|---|
| Registration, login, sessions | ✓ | ✓ | ✓ same-email registration race | ✓ rate limits, 72-byte bcrypt rule, array email | — | ✓ CSRF enforced (`CsrfProtectionTest`), session fixation, logout while other requests are in flight, per-account limit | ✓ |
| Authorization (owner-only) | — | ✓ | — | — | ✓ `RouteContractTest` (401 on every protected route) | ✓ `CrossUserAccessTest` (every project GET route) | ✓ |
| Projects | ✓ | ✓ | ✓ same-slug creation race | ✓ archived projects | ✓ 405/404 envelope | ✓ | ✓ |
| Uploads and ZIP inspection | ✓ | ✓ | ✓ versions; same key and same or different bytes | ✓ every rejection reason, including data between entries | — | ✓ traversal, symlinks, bombs, hidden entries | ✓ |
| Analysis runs | ✓ | ✓ | ✓ start, double run, sweeper vs renewed run | ✓ queue down at dispatch, stale runs, analyzer errors | ✓ schemas, HMAC vector, source binding | ✓ | ✓ |
| Analyzer service | ✓ | ✓ | ✓ two runs in flight together | ✓ timeouts, limits, malformed archives | ✓ schemas, shared HMAC vector | ✓ URL policy: IP spellings, disguised internal addresses, single DNS lookup | via pipeline |
| Analyzer determinism | ✓ golden fixtures | ✓ | — | — | ✓ same hash across processes and hash seeds | — | — |
| DNA scoring | ✓ | ✓ | ✓ | ✓ unavailable dimensions | ✓ exact values | — | ✓ |
| Competency matrix | ✓ | ✓ | ✓ | ✓ | ✓ exact values | — | ✓ |
| Skill gaps | ✓ | ✓ | ✓ | ✓ | ✓ exact values | — | ✓ |
| AI assessment | ✓ | ✓ | ✓ request and job | ✓ provider failures, queue down, AI turned off while queued, sweeper vs renewed lease | ✓ closed output schema | ✓ prompt has no code, response size cap | ✓ (fake provider) |
| Challenges | ✓ | ✓ | ✓ assign, submit, evaluate, stale takeover | ✓ queue down at dispatch, evaluator unavailable, sweeper vs renewed lease | ✓ real evaluator (`RealEvaluatorIntegrationTest`), status parity | ✓ hidden cases | ✓ |
| Evaluator sandbox | ✓ | — | ✓ both slots at once | ✓ crashes, timeouts, output floods, signals | ✓ result schema through Laravel's client | ✓ users, rlimits, no network, `/dev/shm`, no System V IPC | via challenges |
| Learning roadmap | ✓ | ✓ | ✓ generate, complete, supersede | ✓ | — | — | ✓ |
| Growth tracking | ✓ | ✓ | ✓ | ✓ | — | ✓ trigger-protected rows | ✓ |
| Historical DNA | ✓ | ✓ | — read-only | ✓ | — | ✓ | ✓ |
| GitHub integration | ✓ | ✓ | ✓ import request, duplicate delivery | ✓ GitHub errors, rate limits | ✓ GitHub double | ✓ installation access, sensitive parameters | ✓ (GitHub double) |
| Billing (Phase 23) | ✓ catalog fingerprints, state machine | ✓ API, enforcement on every limited action, webhooks | ✓ last unit, project slots, duplicate deliveries, competing activations, renewal vs cancel | ✓ refunds on failure, stale, deferred and invalid events, expiry | ✓ error codes and types (`check_contracts.py`) | ✓ signature, tolerance, size, no bypass setting, `fake` refused in production, append-only rows | ✓ (fake provider) |
| Teams (Phase 24) | ✓ role rules, invitation tokens and links | ✓ API, every project route for team projects (`TeamProjectAccessTest`), billing subject isolation, analytics | ✓ seat races (acceptance, reactivation), one invitation accepted concurrently, team project slots, owner invariant at commit | ✓ expired, revoked, replayed, wrong-email invitations; archived and suspended organizations; migration with existing data | ✓ error codes and types (`check_contracts.py`) | ✓ cross-organization IDOR, role escalation, owner protection, append-only audit, token hashing, rate limits, access-log redaction (`make verify`) | ✓ |
| Frontend pages | ✓ | — | ✓ late responses (assessment, challenges, GitHub, roadmap, history) | ✓ every project page × loading, 401, 404, 500 and retry, network failure, malformed ID (`page-states.test.tsx`) | ✓ error codes | ✓ no server text shown, React escaping | ✓ |
| Infrastructure | — | — | — | — | — | ✓ `make verify` (networks, headers, Redis auth, spoofed `X-Forwarded-For`) | — |

## Security regression suite

Every Phase 21 fix has a regression test, listed with the fix in
[security hardening](../security/security-hardening.md#findings-and-fixes).
Phase 22 ran the Phase 21 mutation set again (each mutant removes one
fix): every mutant still fails its suite.

Phase 22 adds:

| Control | Test |
|---|---|
| CSRF on login, logout and authenticated writes | `CsrfProtectionTest` |
| Logout holds while other requests of the page are in flight | `SessionLifecycleTest::test_a_request_still_running_during_logout_cannot_bring_the_session_back` |
| Standard error envelope, 405 and 401 on every route | `RouteContractTest` |
| No hidden local ZIP entries | `ZipArchiveInspectorTest` (data between entries) |
| No System V IPC inside the sandbox | evaluator `test_system_v_ipc_objects_cannot_be_created` |
| The sandbox cannot signal the supervisor | `RealEvaluatorIntegrationTest` (signals its parent, signals every process) |
| No IP-literal or disguised internal storage addresses | analyzer `test_url_policy.py` |

## Immutability

| Rows | Model guard (Eloquent update and delete throw) | Database trigger |
|---|---|---|
| Source snapshots, analysis runs (terminal), analysis results | ✓ | — |
| DNA, competency and skill gap snapshots, skill gap results | ✓ | — |
| AI assessments (terminal) | ✓ | ✓ `BEFORE UPDATE` |
| Challenge definitions, challenge submissions (terminal) | ✓ | ✓ `BEFORE UPDATE` |
| Roadmap snapshots, steps, completions | ✓ | ✓ snapshots `BEFORE UPDATE` |
| Growth snapshots and observations | ✓ | ✓ `BEFORE UPDATE` |
| GitHub connections and imports | ✓ | ✓ `BEFORE UPDATE` |

**Limitation.** The model guards do not see query-builder writes
(`Model::query()->update()`, `DB::table()`), and there is no `BEFORE DELETE`
trigger on any table. Deletes are blocked only where a restricting foreign
key points at the row. No application path writes these rows with the query
builder. Earlier phases' tests do so on purpose, to simulate corrupted rows,
which is why broad triggers were not added (see
[historical DNA](../architecture/historical-dna-v1.md#why-no-new-persistence)).

## Mutation testing (Phase 22)

35 mutants of the Phase 22 fixes and of the logic the new tests protect:
34 killed, 1 equivalent.

| Group | Mutants | Result |
|---|---|---|
| Sessions destroyed at logout (`SET ... XX`, driver registration) | 2 | Killed |
| Stale sweepers (analysis, assessment, challenge) | 3 | Killed |
| ZIP layout (gaps, descriptor sizes, descriptor flag) | 4 | Killed |
| AI turned off while queued | 1 | Killed |
| Race branches (upload replay, registration, project slug) | 3 | Killed |
| Queue down at dispatch (analysis, assessment, challenge) | 3 | Killed |
| Error envelope (405, CSRF status, CSRF middleware) | 4 | 3 killed. 1 equivalent: the `TokenMismatchException` arm of the renderer is unreachable, because Laravel turns the exception into an HTTP 419 first. The live 419 mapping is killed |
| Frontend late responses, keys, error codes, page states | 12 | Killed |
| Analyzer URL policy (re-resolution, IP literals, private addresses) | 3 | Killed |

Two mutants first survived. Each got a missing test, then was killed:

- The analyzer's IP-literal check was masked by the port and scheme rules.
  A test now uses HTTPS on port 443.
- The roadmap page's load ordering had no test. A test now navigates to
  another roadmap while the first one is still loading.

## Residual gaps

| Gap | Why it is open | Mitigation |
|---|---|---|
| Browser E2E is not in CI | Playwright is not a project dependency yet | Run per phase; component, contract and smoke tests in CI |
| No coverage percentage | No coverage driver in the images | Behavior matrix above |
| A project update racing an archive is not tested with real concurrency | A correct order and the bug end in the same final state, so a race test cannot tell them apart | The status is checked on the locked row; mutation-tested since Phase 21 |
| Rate limiters for analysis, challenges, GitHub, the global API limit and the hourly limits have no dedicated test | Configuration, not logic; the limiter mechanism is tested on login, registration and uploads | Listed for a later phase |
| Query-builder writes to model-guarded rows | See [immutability](#immutability) | No application path does it |
| An upload retried after the project was archived answers `409 PROJECT_ARCHIVED`, not the original snapshot | Documented precondition (`Project ACTIVE`); challenge submissions replay instead | Clients treat 409 as final; revisit with idempotency semantics |
| The local (development) storage host accepts 6to4 and Teredo addresses that embed loopback | They count as private; the local host is a Docker DNS name an attacker does not control | Public hosts refuse them (tested) |
| GitHub imports have no stale sweeper | A stuck import stays `RUNNING` until the next import request | Listed for a later phase |
