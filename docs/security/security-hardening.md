# Security Hardening (Phase 21)

Phase 21 was a systematic security pass over the whole system. Its scope:

- the Laravel API;
- the Next.js frontend;
- the analyzer and the challenge evaluator;
- the GitHub and AI integrations;
- storage, Redis and the containers;
- the dependencies.

It added no features and changed no scoring or analysis behavior. The
threat model is in [threat-model.md](threat-model.md).

**How findings were verified.** Each one was confirmed against the code,
and where possible live on the running stack, before it was fixed. Every
fix has a regression test, and the security-critical ones were
mutation-tested: weakening the control makes a test fail. A finding that
turned out not to apply is listed as such.

## Findings and fixes

| # | Severity | Component | Finding and exploit | Fix | Regression test |
|---|---|---|---|---|---|
| 1 | P1 | Nginx / Laravel | **Client IP spoofing.** Laravel trusts `X-Forwarded-For` from private addresses, and Nginx passed the client's own header through. Sending a different `X-Forwarded-For` on each request escaped every IP-keyed limit, giving unlimited password guessing and registrations. Confirmed live: 14 attempts, no 429. | Nginx overwrites the header with the TCP peer for Laravel and Next.js. The Next.js server's session checks use an internal listener (`nginx:8080`, unpublished) that alone takes the forwarded visitor address. | `make verify`: "spoofed X-Forwarded-For does not escape the login rate limit" (mutation: removing the override fails it) |
| 2 | P1 | Login rate limit | A distributed guesser got 5 attempts per minute per IP against one account, with no per-account bound. | Added 30 attempts per hour per account, across all IPs. | `LoginTest::test_is_rate_limited_per_account_across_ips` |
| 3 | P1 | Containers | **The analyzer could reach PHP-FPM.** The HTTP backend (FastCGI on `backend:9000`) shared the internal network with the analyzer, which parses untrusted archives. A compromised analyzer could send raw FastCGI (`PHP_VALUE auto_prepend_file`) and get code execution with every secret. The frontend could also reach FPM, an unauthenticated Redis (sessions and queued jobs the worker unserializes), PostgreSQL and MinIO. | Three networks: edge (nginx, frontend), app (nginx, Laravel, data services) and internal (queue worker, analyzer, MinIO). The HTTP backend left the internal network, since only the queue worker calls the analyzer. Redis now requires a password. | `make verify`: 9 reachability checks plus "Redis refuses unauthenticated clients" |
| 4 | P1 | Challenges | **Hidden test inputs leaked.** Submitted code chooses its exception class names, and the error name of every case, hidden ones included, was returned to the user. `raise type(repr(args), (Exception,), {})()` exposed hidden inputs, 64 characters per case, and load errors could read all cases through frame inspection. | Hidden cases and load errors show only Python's builtin exception names and the runner's own markers; anything else is `Error`. Enforced in the evaluator and again in Laravel. Visible cases keep their names. | `ChallengeGraderTest::test_hidden_case_errors_never_carry_submitted_text`, evaluator `Phase21HardeningTest`, `Phase21SandboxTest` |
| 5 | P1 | Evaluator | **Free or repeated evaluations.** A deeply nested return value made result handling raise an error. The claimed request was then removed without a result, so Laravel timed out and resubmitted the same code (repeated execution), or the run ended as an ungraded `ERROR` that consumed no attempt. | Every claimed request gets exactly one result (`CRASHED` on any unexpected error). Values nested more than 32 levels are a `ValueTooDeep` case error, checked iteratively. | evaluator `test_an_unexpected_failure_still_writes_a_result`, `test_deeply_nested_values_are_errors_not_crashes`, `test_a_deeply_nested_return_value_is_graded_not_dropped` |
| 6 | P2 | Evaluator | `/dev/shm` was Docker's world-writable 64 MiB tmpfs. Files there outlived a job, were shared between slots and users, and counted against the memory limit. A directory set to `chmod 000` also survived slot cleanup. | `/dev/shm` is a 64 KiB root-owned tmpfs. Cleanup is iterative, restores permissions before descending, and fails closed: a slot it cannot empty is taken out of rotation. No POSIX message queues (`RLIMIT_MSGQUEUE 0`). Sandboxed processes are the OOM killer's first choice (`oom_score_adj 1000`). | `test_shared_memory_is_not_writable`, `test_unreadable_directories_are_still_cleaned`, `test_sandboxed_code_is_the_first_choice_of_the_oom_killer` |
| 7 | P2 | Authorization | **Project existence oracle.** On list routes, validation ran before authorization: another user's project answered `422` for invalid input and `404` otherwise, confirming that the ID exists. | Project-scoped list and compare requests authorize the project before validation. | `CrossUserAccessTest` (every project GET route, 4 query variants, plus nested resources through another project) |
| 8 | P2 | Analyzer → Laravel | A correctly signed result for the right run but computed over other bytes (analyzer bug, stale cache) was stored and scored. | The result's `source.sha256` and `size_bytes` must equal the snapshot's. | `AnalyzerClientTest` "result for other source bytes", "result for a source of another size" |
| 9 | P2 | Analyzer | **Unsigned bodies were buffered whole.** The body size limit applied only to a declared `Content-Length`. An unsigned chunked body was buffered entirely before the signature check, so a neighbouring container could exhaust the analyzer's memory. | The body is read chunk by chunk and refused just past 64 KiB. | `test_the_endpoint_stops_reading_an_endless_body_just_past_the_limit` (an endless body through the real ASGI app) |
| 10 | P2 | Analyzer | A public storage host was accepted on any port, so an allow-listed name could probe other ports. | Non-local hosts are reached on 443 only. | `test_a_public_host_is_only_reached_on_the_https_port` |
| 11 | P2 | GitHub | **Another organization's installation.** A public repository is readable with any user token, so a user could connect it and import through another organization's installation of the App (its tokens and rate limit). | Connecting and importing require the installation to be one the user can access (`GET /user/installations`). | `test_another_accounts_installation_is_refused`, `test_an_import_needs_access_to_the_installation` |
| 12 | P2 | GitHub | Token, code, state and private-key parameters were not `#[\SensitiveParameter]`, and PHP recorded argument values in exception traces, so tokens could reach logs or `failed_jobs`. | Parameters are marked, and `zend.exception_ignore_args = On`. | `SensitiveParametersTest`, `make verify` (ini) |
| 13 | P2 | AI | The provider response was read whole before the size check. | Streamed with a cap, and an oversized `Content-Length` is refused before reading. | `test_an_oversized_declared_length_is_refused_before_reading` (mutation-tested together with the read cap) |
| 14 | P2 | Configuration | Production checks (`APP_DEBUG=false`, https URLs, secure cookies, no fake AI provider) applied only when `APP_ENV` was exactly `production`, so `staging` or `prod` was exempt. A CORS wildcard with credentials was not refused. | Every environment except `local` and `testing` gets the production checks. CORS origins must be exact, and origin patterns are refused. | `ConfigurationValidatorTest` (2 new tests) |
| 15 | P2 | HTTP | No framing protection, CSP, Permissions-Policy or COOP. API responses could be stored in the browser's disk cache. | Nginx sends `X-Frame-Options: DENY` and a CSP limited to `frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`, plus Permissions-Policy and COOP. API responses carry `Cache-Control: no-store, private`, errors included. | `ResponseHeadersTest`, `make verify` (headers on pages and API) |
| 16 | P2 | Login | A non-string `email` (a JSON array) made the rate limiter throw: a 500 and an error log entry per request. | It is keyed as empty and rejected by validation. | `test_a_non_string_email_is_rejected_cleanly` |
| 17 | P2 | Passwords | `max:72` counted characters, so a multibyte password was silently truncated to 72 bytes by bcrypt. | Passwords over 72 bytes are refused. | `test_rejects_passwords_longer_than_72_bytes` |
| 18 | P2 | Projects | A project update racing an archive could land on the archived project. | The status is checked on the locked row. | existing archived-update tests (mutation: removing the check fails them) |
| 19 | P2 | GitHub | Name and SHA patterns ended with `$`, which also matches before a trailing newline. Not exploitable, because other checks catch it, but wrong. | Patterns use `/D`. | `test_a_trailing_newline_is_never_accepted` |

**Not a vulnerability.** The audit suspected that a session making no
request between login and another session's password change would survive
the change. Laravel 13's `SessionGuard::login()` already records the
password hash at login. The suspicion is kept as a regression test
(`SessionLifecycleTest::test_changing_the_password_signs_out_a_session_that_was_never_used_again`).

**Fixed in the tests.** `GitHubConnectionTest` asserted that the
installation ID (`77`) never appears in the response body. A bare "77"
also occurs inside random ULIDs and timestamps, so the test was flaky. It
now checks for 77 as a JSON value.

## Network segmentation

| Network | Members |
|---|---|
| `codedna` (edge) | nginx, frontend |
| `codedna-app` | nginx, backend (PHP-FPM), queue, scheduler, postgres, redis, minio |
| `codedna-internal` (no internet) | queue, analyzer, minio, minio-init |
| none | evaluator |

These are the development networks. Production splits them further:
public, web, app, data, storage, analysis and egress, all internal except
public and egress ([security baseline](../operations/security-baseline.md#network-segmentation)).

The backend PHPUnit suite runs in the `queue` container (`make test`). It
has the same image and code, and it can reach the analyzer for the
integration tests.

**Upgrading an existing checkout.** Run `make setup` once to add the new
`REDIS_PASSWORD` to `.env`, then `make up`.

## Controls verified unchanged (no finding)

- **Sessions:**
  - regenerated on login and registration;
  - invalidated, with the CSRF token rotated, on logout;
  - invalidated on password change, other sessions included;
  - `HttpOnly` cookies, SameSite `lax`, `Secure` required in production.
- **Tokens:** no bearer tokens are accepted (the `User` model has no API
  tokens), and nothing is kept in `localStorage`.
- **CSRF:** enforced on every state-changing route.
- **Errors:** responses never carry exception messages. Logs carry IDs,
  codes and exception class names only.
- **Mass assignment:** only three models declare fillable fields; every
  other model is written with `forceFill` from server-built values.
- **SQL:** no raw SQL interpolation; every raw fragment uses bindings.
- **Analyzer HMAC:**
  - constant-time comparison;
  - ±300 s window;
  - a bounded replay cache keyed by request ID;
  - the signature covers method, path, request ID and the raw body hash;
  - duplicate JSON keys are refused;
  - authentication failures are never retried.
- **Pre-signed URLs:** generated per attempt, valid 15 minutes, and never
  stored, logged or queued. The job payload is the run ID only.
- **ZIP handling:** no extraction in Laravel. In the analyzer: traversal,
  absolute and drive paths, links, special files, duplicates, encryption,
  bombs and overlaps are refused.
- **Evaluator isolation:**
  - no network and no secrets;
  - a separate unprivileged user per slot, with rlimits;
  - output capped;
  - stdout lines parsed as untrusted;
  - expected outputs never sent.
- **GitHub OAuth state:** 32 random bytes, stored hashed, valid 10
  minutes, consumed atomically, bound to the user.
- **GitHub tokens:** user tokens encrypted at rest. Installation tokens
  held in memory only, limited to one repository and read-only, and never
  sent to the archive host.
- **AI:** no source code or user-written text in prompts, closed output
  schema, output rendered as text only.
- **Frontend:** no `dangerouslySetInnerHTML`, no open redirects (fixed
  targets or validated IDs; the GitHub redirect is limited to http and
  https), and no server error text shown.

## Dependency audit

| Ecosystem | Tool | Result |
|---|---|---|
| PHP | `composer audit --locked` | No advisories |
| npm (runtime) | `npm audit --omit=dev` | 0 vulnerabilities |
| npm (all) | `npm audit` | 9 "high", all from one advisory: `braces` ≤ 3.0.3 (GHSA-vfj7-8cjw-p6xm, stack exhaustion on hostile glob patterns). Reached only through dev tools (`shadcn` CLI, `eslint-config-next`). No patched release exists, and it never processes user input or ships in the application. Documented, not changed. |
| Python (analyzer) | `pip-audit -r requirements.txt` / `requirements-dev.txt` | No known vulnerabilities |
| Python (evaluator) | — | Standard library only |

## Residual risks

| Risk | Why it is acceptable now | Plan |
|---|---|---|
| In development the evaluator shares the host kernel (`runc`) | Development only. Production requires gVisor, attested at start, with no fallback (Phase 25, [production sandbox](../architecture/challenge-evaluator.md#production-sandbox)) | — |
| The sandbox shares an interpreter with the runner, so submitted code can forge stdout records about its own cases | Grading is in Laravel against expected values the evaluator never sees. A forged record can only change the submission's own outcome, never produce a pass for wrong values. | Separate processes per case if verdict integrity needs more |
| The evaluator uses `preexec_fn` in a threaded supervisor (a theoretical fork-time deadlock) | It would only stall the evaluator; the heartbeat makes it visible | Move to `Popen(user=, group=, process_group=)` and an exec wrapper |
| The development CSP has no `script-src` | The Next.js development server needs inline and eval'd scripts. Production pages get a per-response nonce CSP without `unsafe-eval` (Phase 25, [CSP](../operations/security-baseline.md#content-security-policy)) | — |
| No HSTS in development | There is no TLS in the development stack. The production Nginx sends HSTS on HTTPS only (Phase 25) | — |
| DB-level immutability triggers exist for growth, GitHub, AI, challenge and roadmap rows, but not for DNA, competency and skill gap rows (model guards only) | Earlier phases' tests deliberately tamper with those rows; no application path updates them | Add triggers with a test-only bypass |
| Unlinking GitHub does not revoke the tokens at GitHub | The tokens are deleted locally; a user token expires within 8 hours | Call GitHub's token revocation on unlink |
| The per-account login limit (30 per hour) lets an attacker who keeps guessing block that account's logins for up to an hour | Standard trade-off against unlimited distributed guessing; the account itself is never locked, and an existing session keeps working | Per-account CAPTCHA or email unlock instead of a hard limit |
| No breached-password check | It would require sending password-hash prefixes to an external service | Optional `uncompromised()` in production |
| Registration reveals whether an email is registered | Common practice; registration is rate limited per IP (now the real IP) | Email-verification flow later |
| The dev stack runs as the host user with writable source mounts, and most dev services have no `cap_drop` or `read_only` | Development only. Production images and `docker-compose.prod.yml` harden every service (Phase 25, [security baseline](../operations/security-baseline.md#containers)) | — |
| More than 100 GitHub installations per user are not paged | Real users have a handful | Paginate `GET /user/installations` |
| Production pages accept inline style attributes (`style-src-attr 'unsafe-inline'`) | Used for widths and positioning. A style attribute cannot run script; scripts and `<style>` elements need the nonce | Replace with CSS variables set from classes |
| gVisor attestation relies on gVisor's fixed synthetic kernel identity | A change in a gVisor release fails closed (the evaluator refuses to start), never open | Update the fingerprint with the gVisor upgrade |
| PHP-FPM is reachable from the queue and scheduler on the data network | They are trusted Laravel processes with the same code and configuration | Bind PHP-FPM to the app network only, if the topology changes |
| Postgres keeps `CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `SETUID` and `SETGID` | The official entrypoint initialises the data volume as root, then drops to the `postgres` user | A pre-initialised volume and `user: postgres` |

## Secrets policy

- Secrets live only in `.env`, which is git-ignored and generated by
  `make setup` with random values. `.env.example` keeps every secret empty,
  and `scripts/check_repo.py` enforces that.
- Compose refuses to start without the secrets (`:?`), so there are no
  default credentials.
- Secrets are never logged. Token, code and key parameters are
  `#[\SensitiveParameter]`, and traces omit argument values.
- Never sent to the browser: GitHub, AI and storage credentials, pre-signed
  URLs and the HMAC secret.
- Gitleaks scans the history and the working tree in CI (`make scan-secrets`).

## Production checklist

The production profile now implements and checks this checklist (Phase 25).
See [production deployment](../operations/production-deployment.md),
[production configuration](../operations/production-configuration.md) and
the [security baseline](../operations/security-baseline.md). What remains is
the operator's:

1. Keep `/etc/codedna/production.env` outside the repository (mode 0600),
   with secrets generated once and also held in the secret manager.
2. Install and register gVisor; run `make prod-evaluator-attest`.
3. Behind an HTTP load balancer, configure `real_ip` for its addresses only.
4. Rotate `APP_KEY` (re-encrypts GitHub tokens), `ANALYZER_HMAC_SECRET`
   (the previous secret is supported during rotation), the GitHub App key,
   the AI key and storage keys after any suspected exposure.
5. Before each release: `make check`, `make test`, `make prod-smoke`,
   `make scan-secrets` and `make audit`; back up before migrating
   ([backup and restore](../operations/backup-and-restore.md)).
