# Security and data ownership (self-hosted)

What a self-hosted installation protects, who owns what, and what Phase 27
audited and changed.

The full model is in:

- the [threat model](../security/threat-model.md);
- [security hardening](../security/security-hardening.md);
- the [production security baseline](../operations/security-baseline.md).

## Data ownership

- **A developer's CodeDNA belongs to that developer.** Personal projects,
  their DNA, competencies, skill gaps, growth, history, roadmaps and
  challenges are visible only to their owner. Joining, leaving or being
  removed from a team never changes or exposes them.
- **Team projects belong to the organization.** Members work on them
  according to their role ([teams authorization](../teams/authorization.md)).
- **Team analytics** shows aggregate figures from team projects only. It
  never shows personal projects, never averages thin evidence, and never
  mixes versions ([team analytics](../teams/team-analytics.md)).
- **The installation owns its data store.** In a self-hosted installation,
  every byte (database, Redis, uploaded source, logs) stays in the
  infrastructure you run. Nothing is sent to the CodeDNA vendor: there is no
  telemetry, license check or update check.
- **Optional integrations send data out:**
  - **GitHub:** downloads repositories you connect;
  - **an AI provider:** receives the analysis summary of an assessment the
    user requests, never source code
    ([AI interpretation](../architecture/ai-assessment-v1.md)).

  Both are off unless configured.

## Isolation

| Boundary | Control |
|---|---|
| Organization ↔ organization | Every team resource is resolved through the caller's ACTIVE membership; a non-member gets `404`, a member without the role `403`. A license changes plans and seats only, never access (tested: `EnterpriseEditionTest`) |
| User ↔ user | Personal resources are owner-only (`404` for anyone else), including in cursor pages and history comparisons |
| Installation operator ↔ users | **There is no installation administrator role.** Operators configure the host; no API lets anyone read other users' or other organizations' data, and no setting creates such a role |
| Browser ↔ storage | The browser never receives a storage URL or credential |
| Analyzer ↔ storage | The analyzer receives only pre-signed GET URLs valid for `SOURCE_URL_TTL_SECONDS` (900 s, validated 60–3600), checked against a host allow-list and SSRF rules; it holds no storage credential |
| Untrusted code | Runs only in the evaluator, with no network, under gVisor (refused otherwise in production) |
| Services | Internal networks per tier; only Nginx publishes ports; read-only root filesystems; all capabilities dropped (`make prod-config` enforces it, with and without the external-service overlays) |

## Phase 27 audit

**Findings that led to changes:**

| Finding | Change |
|---|---|
| The documented "use a managed database / external S3" setup required editing the production compose file. The scheduler, migration job and analyzer had no route to an external host, and `REDIS_HOST` was fixed to `redis` | Compose overlays for customer-run PostgreSQL, Redis and storage, checked by `make prod-config`. `REDIS_HOST`/`PORT`/`SCHEME` are configurable |
| A database or Redis outside the private network could be used without TLS (`DB_SSLMODE=prefer` silently accepted; Redis had no TLS option) | Boot refuses a non-internal `DB_HOST` without `require`/`verify-ca`/`verify-full`, and a non-internal `REDIS_HOST` without `REDIS_SCHEME=tls` |
| Any visitor could create an account; a company installation reachable beyond its staff had no way to limit that | `REGISTRATION_MODE` (`open`/`restricted`/`closed`), enforced on the server |
| No way to check an installation's dependencies before starting it | `codedna:preflight`: read-only, names dependencies, never values |

**Reviewed without findings:**

- organization policies and membership checks;
- the audit log (append-only; covers every membership, role, invitation,
  project and organization change);
- signed URL lifetime and scope;
- the log redaction of tokens and secrets;
- error responses: internal errors return a request id, never an exception
  message.

## Licensing security

- **Keys:** only public keys ship. The private key never enters the
  repository, an image, CI or an example file
  ([licensing](licensing.md#keys)).
- **Checks:** the signature is verified before any claim is read. Unknown
  claims are refused, never ignored.
- **Fails closed:** every failure means the Community edition, never
  "assume valid".
- **No secrets disclosed:** the license document, signature, key id,
  installation host and file path are never returned by the API. The logs
  carry only the status, the license id and the expiry.
- **No access granted:** a license grants plan allowance and seats to
  organizations. It never grants access, an administrator role or anything
  personal.

## Operator checklist

- **Secrets:** keep the environment file `0600` and outside the repository.
  Generate secrets as in the runbook. Never reuse the example values (there
  are none).
- **Network:** restrict the egress routes you enable with the overlays to
  the exact database, Redis and storage hosts.
- **TLS:** use `verify-full` for PostgreSQL, and TLS with a publicly
  trusted certificate for Redis.
- **Registration:** set `REGISTRATION_MODE=restricted` (or `closed`) unless
  the installation should accept anyone who can reach it.
- **Backups:** back up the database and object storage, and run restore
  drills.
- **Logs:** collect them, alert on `license.not_granting`, `5xx` and failed
  jobs.
- **Patching:** keep the host, Docker and gVisor patched. Upgrade CodeDNA
  releases for security fixes.
