# Enterprise architecture

How a self-hosted CodeDNA installation is put together, where the Community
and Enterprise editions differ, and what stays the same in both (Phase 27).

Related:

- [self-hosted installation](self-hosted-installation.md)
- [configuration reference](configuration-reference.md)
- [licensing](licensing.md)
- [upgrade and rollback](upgrade-and-rollback.md)
- [security and data ownership](security-and-data-ownership.md)
- [troubleshooting](troubleshooting.md)
- [ADR-013](../decisions/ADR-013-enterprise-licensing.md)

## One codebase, one deployment

A self-hosted installation runs the same images and the same
`docker-compose.prod.yml` as any production deployment
([production deployment](../operations/production-deployment.md)). There is
no separate "enterprise build", no feature branch and no second organization
system. Phase 27 adds four things:

1. **An edition**, decided by an offline-verified license file
   ([licensing](licensing.md)).
2. **Self-hosted settings**: registration control, and customer-run
   PostgreSQL, Redis and S3-compatible storage
   ([configuration reference](configuration-reference.md)).
3. **Operator commands**: `codedna:preflight` checks an installation before
   it starts; `codedna:license` shows the license.
4. **Status**: `GET /api/v1/installation`, shown on the billing page, and
   the plan source on each team's page.

```text
                 license file (read-only secret)        config/license.php
                          │                              (trusted PUBLIC keys,
                          ▼                               shipped in the release)
             EnterpriseEdition ── LicenseVerifier (Ed25519, offline) ◄──┘
                │          │
                │          └──► GET /api/v1/installation, codedna:license (status only)
                ▼
  BillingContextResolver::resolveOrganization ──► plan of every organization
  QuotaService::seats                         ──► seat limit of every organization
                                                  (personal billing never asks)
```

## Editions

| | Community (no license) | Enterprise (valid license) |
|---|---|---|
| Personal accounts, projects, analysis, DNA, competencies, skill gaps, growth, history, roadmaps, challenges | ✓ | ✓, unchanged |
| Personal plan | FREE, or PRO through a subscription | the same: a license never changes personal billing |
| Organizations (teams), roles, invitations, audit log, team analytics | ✓ | ✓, unchanged |
| Organization plan | the plan in its billing account (FREE) | the license's plan (`TEAM_READY`: every feature, team-sized quotas) |
| Organization seats | the account's limit (5) | the license's limit, or the account's when higher |
| Registration control, TLS to external services, preflight, audit logging, every security control | ✓ | ✓ |

**Security controls are never edition-gated.** Registration control,
validation, isolation, audit logging and the evaluator sandbox work the same
in both editions.

## Where entitlements are decided

The billing domain stays the only place feature and quota decisions are
made ([billing architecture](../billing/billing-architecture.md)). The
license feeds it at exactly two points:

- `BillingContextResolver::resolveOrganization()`: with a valid license, an
  organization's context uses the license's plan. Otherwise it uses the plan
  its billing account stores.
- `QuotaService::seats()`: the seat limit is the larger of the account's and
  the license's.

Everything downstream is unchanged and keeps every Phase 23/24 rule:
`Entitlements::require()`, quotas, the usage ledger, locked counters,
refunds and `402` answers. Controllers contain no edition checks.

**Nothing is stored.** Billing accounts keep their own plan and seat limit.
When a license expires, is removed or stops verifying, organizations return
to their account's plan and seats on the next request:

- no data is deleted;
- no member is removed;
- seats over the limit stay taken, but no new seat is granted until the
  count is below the limit again.

## Edition state

`App\Services\Enterprise\EnterpriseEdition` is the only class that reads
the license. It is a scoped service: it reads and verifies the file once
per HTTP request or queued job. So:

- a renewed license applies without a restart;
- an expiry applies at the exact second, even in a long-running worker.

It fails closed. Any problem (unreadable file, bad signature, unknown key,
another installation, expired) means Community behavior.

## Status

`GET /api/v1/installation` answers any signed-in user with:

- the edition;
- the license status;
- for a valid license, the licensee, expiry, team plan and seats;
- the registration mode.

It never returns:

- the license document, signature, signing key id or installation host;
- the file path;
- any configuration value.

The response is informational. The interface uses it to explain what
applies, never to decide anything.

There is no installation-wide administrator role. Installation settings are
the operator's: they live in the environment file and the license file on
the host. Organization owners and admins manage their own organizations,
exactly as before ([teams authorization](../teams/authorization.md)).

## What Phase 27 does not include

These are not part of Phase 27:

- GitLab or Bitbucket;
- a local AI provider (Phase 29);
- a payment gateway or license server;
- single sign-on;
- an installation admin console;
- Kubernetes manifests.

There is no online license check of any kind.
