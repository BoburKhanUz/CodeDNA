# Team billing boundary (Phase 24)

Phase 23 made the user a billing subject. Phase 24 makes the organization one
too, without payments and without forking the billing domain.

```text
Personal billing:  User         -> Subscription (Phase 23) or FREE fallback
Team billing:      Organization -> Organization billing account (plan reference, seats)
```

The two never mix:

- A user's personal subscription never becomes the team's. A team membership
  never grants anyone personal PRO.
- A **team project** is measured against the **organization's** plan and
  counters, never its creator's or the acting member's.
- A **personal project** never uses team quota.
- The usage ledger (`billing_usage_events`) records exactly one subject per
  entry: `user_id` or `organization_id` (CHECK).
- `GET /api/v1/billing` stays personal: it counts personal projects only, and
  its usage list never shows team usage.

## How it works

The Phase 23 services are extended, not copied:

| Piece | Phase 24 change |
|---|---|
| `BillingContext` | Has exactly one subject: `userId` or `organizationId` |
| `BillingContextResolver` | `resolveOrganization()` (the account's plan, per UTC calendar month) and `resolveSubject()`: a project's subject is its organization, or its owner when personal |
| `Entitlements`, `UsageService` | Accept a user, an organization or a project; consumption, refunds and refusals work the same, on the subject's counters |
| `QuotaService` | `ACTIVE_PROJECTS` counts the subject's own projects (personal projects of a user, or the organization's); `requireProjectSlot()` locks the subject's row; seats (below) |
| Enforcement points | `CreateProject`, uploads, analyses, assessments, challenges, roadmaps and GitHub imports pass the project instead of its owner |

Every rule from [Phase 23](../billing/entitlements-and-quotas.md) applies to
team projects unchanged:

- the locked counter;
- one charge per resource;
- the refund on failure;
- `402` errors with the same details.

## The organization billing account

`organization_billing_accounts` holds the organization's plan reference (a
stored plan version), its entitlement version and its seat limit. It is
created with the organization from **team entitlements 1.0.0**
(`App\Services\Billing\Catalog\TeamEntitlementsV1`):

| Entitlement | Value |
|---|---|
| Plan | `FREE` (newest active version) |
| Seats | 5 ACTIVE memberships, the owner included |

There is no API, setting or command that changes it. `GET .../billing` is
read-only, and every write method answers `405`. There is no checkout, no
subscription, no payment and no fake transaction. The reserved `TEAM_READY`
plan stays reserved: no provider event can activate it (`UNKNOWN_PLAN`), and
no organization is created on it.

## Seats

A seat is an ACTIVE membership. REMOVED and SUSPENDED memberships and pending
invitations take no seat. A seat is taken:

- when an invitation is accepted;
- when a suspended member is reactivated.

`QuotaService::requireSeat()` runs inside the transaction that activates the
membership, while that transaction holds the organization row lock. Two
concurrent acceptances or reactivations therefore cannot both take the last
seat (`402 SEAT_LIMIT_REACHED`, with `limit` and `used`).

## Phase 25 and later

When team payments arrive, a provider event will change the organization's
billing account:

- its plan version (for example a purchasable team plan);
- its seat limit.

That change goes through the same verified-webhook path as personal
subscriptions. The rest of the domain (counters, ledger, seats, enforcement)
needs no change.
