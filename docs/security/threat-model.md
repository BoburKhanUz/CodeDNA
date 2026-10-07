# Threat Model

Phase 21. This document describes who CodeDNA defends against, where the
trust boundaries are, and which controls hold each boundary. The fixes made
in Phase 21 and the remaining risks are in
[security-hardening.md](security-hardening.md).

## Assets

| Asset | Where it lives | Why it matters |
|---|---|---|
| Uploaded and imported source code | MinIO / R2 (private bucket) | Customers' intellectual property |
| Analysis results, DNA, competencies, skill gaps, growth, history | PostgreSQL (immutable rows) | Integrity: scores must reflect the code, nothing else |
| Accounts and sessions | PostgreSQL (bcrypt hashes), Redis (sessions) | Account takeover |
| GitHub user tokens | PostgreSQL, encrypted with `APP_KEY` | Access to users' repositories |
| GitHub App private key, AI API key, HMAC secret, storage keys, `APP_KEY` | Environment only | Each one is a lateral-movement or forgery key |
| Hidden challenge tests | Challenge catalog (server only) | Integrity of challenge verdicts |

## Attacker models

| ID | Attacker | Capability |
|---|---|---|
| A | Unauthenticated internet attacker | Any HTTP request to the public origin |
| B | Authenticated developer | A valid session; any request their browser could make |
| C | Malicious developer seeking cross-project access | B, plus guessed or leaked IDs of other users' resources |
| D | Developer submitting hostile source code | B, plus arbitrary archive contents (paths, sizes, parser-hostile code) |
| E | Developer submitting hostile challenge code | B, plus arbitrary Python executed in the evaluator |
| F | Compromised GitHub-connected account | B, plus control of a GitHub account, repositories and installations |
| G | Malicious repository contents | D, delivered through a GitHub import |
| H | Malicious or misbehaving AI provider | Arbitrary responses to assessment requests |
| I | Compromised or buggy internal service | Code execution in one container (analyzer, evaluator, frontend) |

## Trust boundaries

```text
 internet ──► nginx ──► Next.js (frontend)            [edge network]
                 │
                 └──► PHP-FPM (Laravel) ──► PostgreSQL, Redis, MinIO   [app network]
                                   │
               queue worker ───────┴──► analyzer ──► MinIO (pre-signed GET)   [internal network, no internet]
                    │
                    └── spool volume ──► evaluator   [no network]
```

| Boundary | Crossing | Control |
|---|---|---|
| Browser → Laravel | Session cookie, CSRF token | Sanctum SPA sessions, CSRF on every state-changing request, owner-only policies (404 for others), rate limits, closed input validation |
| User → project → resource | Route IDs, body IDs | Project policy before any read; scoped route bindings; body IDs looked up inside the caller's project; composite foreign keys in the database |
| Laravel → analyzer | HTTP with HMAC-SHA256 | Signed request (method, path, request ID, body hash, timestamp), replay cache, signed responses verified with result hash, run ID, request ID and source hash binding |
| Analyzer → storage | Pre-signed URL | Exact host allow-list, HTTPS port only for public hosts, resolved-address checks, pinned address (no DNS rebinding), no redirects, size cap |
| Laravel → evaluator | Spool files | No network; strict request schema; expected outputs never sent; per-slot unprivileged users with rlimits; results treated as untrusted and graded in Laravel |
| Laravel → GitHub | GitHub App | Per-import installation tokens limited to one repository and read-only; repository, branch, installation and commit verified with GitHub; downloads only from allow-listed origins |
| Laravel → AI provider | HTTPS | No source code or user-written text in prompts; response size-capped and validated against a closed schema; output stored as text, never rendered as HTML |
| Containers → each other | Docker networks | Three networks; the frontend and analyzer reach only what they need |

## How each attacker is stopped

**A. Unauthenticated attacker.** Every API route except health, register and
login requires a session. Login and registration need a first-party session
and a CSRF token. They are rate limited per IP (the real TCP peer), per email
and IP, and per account across all IPs. Login errors do not reveal whether an
account exists. Error responses never contain exception messages, SQL, paths
or stack traces.

**B and C. Authenticated and cross-project attackers.**

- Every project-scoped route authorizes the project first. For routes with
  query validation this happens before validation, so another user's project
  is always `404 RESOURCE_NOT_FOUND` and never a `422` that would confirm it
  exists.
- Nested resources are scope-bound to their project.
- IDs in request bodies are looked up within the caller's project.
- Composite foreign keys make cross-project lineage impossible in the
  database itself.
- `CrossUserAccessTest` checks every project GET route against another user
  and through another project.

**D and G. Hostile source code.**

- Laravel inspects ZIP structure without extracting it: limits on paths,
  types, sizes, entries and compression ratio.
- The analyzer extracts into a private tmpfs workspace with `O_EXCL` and
  `O_NOFOLLOW`. Parsing is iterative and bounded per file and per run, and
  the code is never executed.
- The analyzer has no internet and no credentials, and reaches only MinIO.

**E. Hostile challenge code.**

- The evaluator has no network, a read-only root filesystem and no secrets.
- Each job runs as a separate slot user: no-new-privileges, CPU, memory,
  process, file-size, open-file and message-queue limits, a wall-clock
  timeout, and a kill of every process of that user.
- The slot directory is emptied after every job, and the evaluator stops
  using a slot it cannot empty.
- Results are untrusted. Error names are reduced to builtin exception
  names, value depth and size are capped, and every claimed request gets
  exactly one result.

**F. Compromised GitHub account.**

- The account can only import repositories it can reach through an App
  installation it has access to.
- Installation tokens are minted per import, for one repository, read-only.
- Imports go through the same archive checks as uploads.
- User tokens are encrypted at rest and never returned or logged.

**H. Malicious AI provider.**

- The prompt carries no source code or user-written text.
- The response is size-capped while it streams, parsed with a depth limit,
  validated against a closed schema and content rules, and stored as plain
  text.
- Provider errors are reduced to fixed codes.
- The fake provider is refused in every non-local environment.

**I. Compromised internal service.**

- **Analyzer:** cannot reach PHP-FPM, Redis or PostgreSQL. A result signed
  for another run or for other source bytes is rejected.
- **Frontend:** cannot reach PHP-FPM, Redis, PostgreSQL or MinIO.
- **Evaluator:** has no network at all.
- **Redis:** requires a password.

## Assumptions

- The host and Docker daemon are trusted, and only trusted operators can
  run `docker compose exec`.
- In production, TLS terminates at a trusted edge, which sends HSTS and
  real client addresses (see the production checklist).
- `APP_KEY` and the other secrets are kept out of the repository and out of
  logs, and rotated after an incident.
- The kernel is shared: the evaluator sandbox relies on Linux users,
  rlimits, cgroups and Docker's default seccomp profile, not a separate
  kernel (see residual risks).
