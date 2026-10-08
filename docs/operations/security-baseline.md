# Production security baseline

What the production profile (Phase 25, [`docker-compose.prod.yml`](../../docker-compose.prod.yml))
guarantees, and how it is checked. Earlier hardening (Phase 21/22: spoofed
client IPs, login limits, CSRF, session resurrection) is in
[security hardening](../security/security-hardening.md); the threats are in
the [threat model](../security/threat-model.md).

Checks: `make prod-config` ([`scripts/check_production.py`](../../scripts/check_production.py),
static, in CI) and `make prod-smoke` ([`scripts/smoke-production.sh`](../../scripts/smoke-production.sh),
runtime, in CI).

## Network segmentation

| Network | Internal | Members | Purpose |
|---|---|---|---|
| `public` | no | nginx | The only published ports: 80 (redirect) and 443 |
| `web` | yes | nginx, frontend | Next.js upstream; Next.js → Nginx internal listener (8081). Fixed subnet `172.30.20.0/24` |
| `app` | yes | nginx, backend | FastCGI to PHP-FPM. Fixed subnet `172.30.10.0/24` = `TRUSTED_PROXIES` |
| `data` | yes | backend, queue, scheduler, migrate, postgres, redis | Database and Redis |
| `storage` | yes | backend, queue, minio, minio-init | Object storage API |
| `analysis` | yes | queue, analyzer, minio | Signed analyzer calls; pre-signed downloads |
| `egress` | no | backend, queue | Outbound GitHub, AI provider, external storage |
| — | — | evaluator | `network_mode: none`; files through the spool volume only |

The smoke test verifies the consequences:

- The **analyzer** reaches only MinIO. It cannot reach PHP-FPM, PostgreSQL,
  Redis, Nginx or the internet.
- The **frontend** reaches only Nginx's internal listener. It cannot reach
  PHP-FPM, PostgreSQL, Redis, MinIO, the analyzer or the internet.
- The **scheduler** has no internet route.
- The **backend** cannot reach the analyzer.
- The **evaluator** has no network interface.

Accepted within the data network: the trusted Laravel processes (`queue`,
`scheduler`) can open PHP-FPM's port. They run the same code with the same
configuration, so this grants nothing new.

## Containers

Every service runs with:

- a read-only root filesystem (writable paths are size-limited tmpfs or
  named volumes);
- `no-new-privileges`;
- `cap_drop: ALL`;
- memory, CPU and PID limits;
- `restart: unless-stopped`;
- rotated JSON logs.

There are no bind mounts, apart from the two TLS files delivered as Compose
secrets. No container is privileged or joins a host namespace, and none
mounts the Docker socket.

| Service | User | Capabilities added | Why |
|---|---|---|---|
| nginx | 101 | — | Unprivileged ports inside the container (8080, 8443, 8081) |
| frontend | 1000 | — | |
| backend, queue, scheduler, migrate | 10010 | — | Code owned by root, read-only |
| analyzer | 10001 | — | |
| evaluator | root supervisor | SETUID, SETGID, KILL | Drops each job to its slot user; code never runs as root |
| postgres | root → postgres | CHOWN, DAC_OVERRIDE, FOWNER, SETUID, SETGID | The official entrypoint initialises the volume, then drops privileges |
| redis | 999 | — | |
| minio | 65532 | — | |

Production images:

- are built from multi-stage `production` targets: no compilers, Composer,
  npm, pip, tests or development dependencies;
- contain the application owned by root and read-only;
- run PHP with `php.ini-production`, `display_errors=Off` and OPcache
  without timestamp checks;
- run Next.js as the standalone server;
- run the analyzer without `--reload`.

## TLS and security headers

- Nginx terminates TLS 1.2/1.3 with forward-secret AEAD suites. Session
  tickets are off.
- Unknown server names are rejected at the handshake. A valid handshake
  followed by another `Host` gets 421.
- HTTP answers only with a 301 to `https://<CODEDNA_DOMAIN>`, never to a
  client-chosen host.
- Every HTTPS response, errors included, carries these headers:
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains`;
  - `X-Content-Type-Options: nosniff`;
  - `X-Frame-Options: DENY`;
  - `Referrer-Policy: strict-origin-when-cross-origin`;
  - `Permissions-Policy` (camera, microphone, geolocation, payment, USB
    off);
  - `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy:
    same-origin`.
- The HTTP listener and the development stack never send HSTS.
- No `Server` version and no `X-Powered-By`.

## Content Security Policy

**Pages** (Next.js, [`frontend/src/proxy.ts`](../../frontend/src/proxy.ts)): a
fresh 128-bit nonce per response.

```text
default-src 'self'; script-src 'self' 'nonce-…' 'strict-dynamic';
style-src 'self' 'nonce-…'; style-src-attr 'unsafe-inline';
img-src 'self' data: blob:; font-src 'self'; connect-src 'self';
media-src 'none'; object-src 'none'; frame-src 'none'; worker-src 'self' blob:;
manifest-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';
upgrade-insecure-requests
```

- Next.js applies the nonce to every script it emits. Every page is
  therefore rendered per request (the root layout opts in with
  `connection()`); a prerendered page could not carry a nonce.
- There is no `unsafe-eval`, no wildcard and no `unsafe-inline` for scripts
  or `<style>` elements.
- **Documented exception:** `style-src-attr 'unsafe-inline'`. Inline style
  attributes set progress-bar widths, and the UI primitives use them for
  positioning. A style attribute cannot execute script.
- Nginx adds the baseline (`frame-ancestors 'none'; object-src 'none';
  base-uri 'self'; form-action 'self'`). Browsers enforce both policies.

**API and health responses:** `default-src 'none'; frame-ancestors 'none';
base-uri 'none'; form-action 'none'`.

The development server keeps the Phase 21 baseline only, because it needs
eval and a WebSocket.

## Forwarded headers and client identity

- Nginx overwrites `X-Forwarded-For` (TCP peer), `X-Forwarded-Proto`
  (`https`), `X-Forwarded-Port`, `Host` and `Forwarded`. It never passes a
  client's own value on.
- Laravel trusts forwarded headers only from `TRUSTED_PROXIES` (the app
  network subnet). The validator rejects wildcards everywhere.
- On the internal listener, the address the Next.js server forwards is
  accepted from the web subnet only.
- The smoke test rotates spoofed `X-Forwarded-For`, `X-Real-IP` and
  `Forwarded` values and still hits the per-IP login limit (Phase 21). A
  spoofed `X-Forwarded-Proto: http` never removes the `Secure` cookie flag.

## Cookies and sessions

- The session cookie (`codedna-session`) is `Secure`, `HttpOnly` and
  `SameSite=Lax`, host-only by default. Its lifetime is bounded (5–1440
  minutes).
- `XSRF-TOKEN` is `Secure` and readable by script (Sanctum's double
  submit).
- Sanctum's stateful domain is `CODEDNA_DOMAIN`. Sessions live in Redis;
  logout cannot be undone by a racing request (Phase 22).

## CORS

Closed by default: same origin only. A deployment may list exact `https://`
origins. Wildcards, patterns and `http://` origins stop the application at
boot.

## Object storage

The bucket is private, with no anonymous access. The application user is
scoped to the bucket. The analyzer gets short-lived pre-signed URLs and
checks their host against an allow-list; private addresses are allowed only
for the internal storage host. No storage credential or URL ever reaches the
browser.

## Evaluator

gVisor is required and attested; there is no fallback
([production configuration](production-configuration.md#evaluator-sandbox)).
The Phase 16 layers still apply inside gVisor:

- no network and no secrets;
- unprivileged slot users;
- rlimits, a read-only root and per-slot tmpfs;
- no SysV IPC and no POSIX message queues.

## Logging

- Laravel logs JSON to stderr.
- `RedactSecrets` removes:
  - passwords, tokens, cookies and session IDs;
  - API, private and storage keys; signatures; DSN credentials;
  - provider tokens (GitHub, OpenAI-compatible, AWS-style);
  - invitation tokens;
  - from the message and from context at any depth.
- Exception traces never include argument values
  (`zend.exception_ignore_args`).
- Nginx logs JSON without query strings and with invitation tokens redacted.
- Source code, prompts and AI responses are never logged.
- The smoke test checks that no generated secret appears in any container
  log, image metadata or the frontend bundle.

## Errors

- `APP_DEBUG=false` is fixed. API errors use the JSON error envelope with a
  request ID, never a trace, SQL, file path or internal URL.
- Nginx's own errors carry no version.
- An oversized upload gets the API's `PAYLOAD_TOO_LARGE` envelope.

## CI

- **foundation:** repository checks, linters (including hadolint of every
  Dockerfile), dev and prod Compose validation, and the production baseline
  check.
- **secrets:** gitleaks over history and the working tree.
- **infrastructure:** every test suite, lint, type checks, the frontend
  production build and dependency audits.
- **production:** builds the production images and runs the production-like
  smoke test.

There is no deployment job, and no production credential exists in CI.
