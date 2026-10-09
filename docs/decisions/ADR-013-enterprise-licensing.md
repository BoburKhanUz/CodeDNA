# ADR-013: Enterprise Edition Through an Offline-Verified License

- **Status:** Accepted
- **Date:** 2026-10-20
- **Related:**
  - [Billing architecture](../billing/billing-architecture.md)
  - [Team billing boundary](../teams/billing-boundary.md)
  - [Enterprise architecture](../enterprise/enterprise-architecture.md)
  - [Licensing](../enterprise/licensing.md)

## Context

Enterprise customers run CodeDNA on their own infrastructure. A self-hosted
installation has no payment provider (`BILLING_PROVIDER=none`), so every
organization is on the FREE plan: 5 seats and small monthly quotas. That
does not fit a company deployment. The reserved `TEAM_READY` plan has
existed since Phase 23 for exactly this case, but nothing can activate it.

Options considered:

1. **An environment flag** (`CODEDNA_EDITION=enterprise`). Trivial, but any
   operator could set it, so it cannot represent an agreement. It would also
   be a frontend-visible switch with nothing behind it.
2. **Writing the plan into each organization's billing account** (a command
   or admin API). That persists entitlements in the database, needs an
   installation administrator (a role that can bypass tenant isolation), and
   does not expire on its own.
3. **A signed license verified offline, applied at runtime through the
   existing billing services.**

## Decision

Option 3:

- **Format.** A license is a JSON envelope carrying a payload (strict
  claims, schema `codedna.license.v1`) and an Ed25519 signature over the
  payload's exact bytes.
- **Keys.** The release ships the issuer's public keys in
  `config/license.php`. They are never read from the environment, and the
  private key never enters the repository, images or CI.
- **Verification.** `LicenseVerifier` is pure: no I/O and no clock of its
  own. It checks the envelope, the key, the signature, then the claims, the
  installation host (`APP_URL`) and the validity period.
- **Edition.** `EnterpriseEdition` (scoped per request or job) reads the
  file mounted as a compose secret. It is the only source of edition
  decisions, and it fails closed to Community.
- **Billing.** The existing billing domain stays the only place
  entitlements are decided. A valid license changes exactly two inputs:
  - the plan `BillingContextResolver::resolveOrganization()` returns (the
    license's plan, v1: `TEAM_READY` only);
  - the seat limit `QuotaService::seats()` returns (never lower than the
    account's).

  Personal contexts never consult the license. Nothing is persisted.
- **Issuance stays out of the repository.** No generator or signing tool is
  included. Tests sign fixtures with a throwaway key from a public seed.
- **Operations.** `codedna:license` shows the status and exits non-zero on
  a license that does not grant. `GET /api/v1/installation` shows a
  non-secret summary.

## Consequences

- **Expiry and removal are reversible and lossless.** Teams return to their
  account's plan and seats on the next request; no member or data is
  removed.
- **Phase 23 rules hold unchanged.** No entitlement logic is duplicated, no
  controller checks an edition, and every Phase 23 quota, ledger and `402`
  rule still applies.
- **No installation works as Enterprise until the issuer publishes a key.**
  The release ships without an issuing key, so licensing is inert until
  the issuer adds its public key in a release. This is the price of never
  generating a key whose private half the issuer does not control.
- **This is commercial enforcement, not DRM.** Someone who modifies the
  code on their own host can bypass it; the license prevents mistakes and
  casual reuse (host binding, tamper-evident claims).
- **Revocation is coarse.** There is no revocation list, only expiry and
  key removal in a release. An online check was rejected: installations
  must work without outbound access.
- **Future entitlements** (other plans, features) need a new claims schema
  version; v1 refuses unknown claims rather than ignoring them.
