# Entitlements and quotas (Phase 23)

What a plan includes (entitlements) and how much of it a user may use
(quotas), and how the server enforces both. See
[Billing architecture](billing-architecture.md) for the overall design.

## Plan catalog v1.0.0

Plans are defined in `PlanCatalogV1` and seeded into `billing_plans` by the
billing migration. Each definition has a pinned SHA-256 fingerprint; a test
fails if a definition changes without a new version. Prices are integer minor
units in USD.

| | FREE | PRO | TEAM_READY |
|---|---|---|---|
| Status | Active | Active | Reserved (not purchasable) |
| Monthly price | 0 | 1500 ($15.00) | not offered |
| Annual price | 0 | 15000 ($150.00) | not offered |
| AI assessment | – | ✓ | ✓ |
| Every other feature | ✓ | ✓ | ✓ |
| Active projects | 3 | 25 | 25 |
| Source uploads / month | 30 | 500 | 500 |
| Uploaded source / month | 500 MiB | 10 GiB | 10 GiB |
| Analyses / month | 60 | 1000 | 1000 |
| AI assessments / month | 0 | 100 | 100 |
| Challenge submissions / month | 60 | 1000 | 1000 |
| Repository imports / month (GitHub, GitLab, Bitbucket Cloud together) | 20 | 300 | 300 |

`TEAM_READY` only reserves the key for future team billing. It has no team
functionality and cannot be activated: an event naming it is `UNKNOWN_PLAN`.

**Rationale.** FREE is meant to be a complete product for one developer with
a few projects: every deterministic feature is included, only AI assessment
(which costs money per call) is excluded. The monthly limits sit well above
normal individual use and well below what would load the analyzer or the
evaluator. Quotas are product limits; they come on top of, never instead of,
the technical limits that already exist (archive size, file counts, rate
limits, AI input size). A request must pass both.

## Features

| Feature | Checked when |
|---|---|
| `PROJECTS` | Creating a project; uploading source |
| `SOURCE_ANALYSIS` | Starting an analysis |
| `AI_ASSESSMENT` | Requesting an AI assessment (after the server's AI-enabled check) |
| `CODING_CHALLENGES` | Assigning a challenge; submitting a solution |
| `LEARNING_ROADMAP` | Generating a roadmap |
| `GROWTH_ANALYTICS` | Listed for display; growth is computed from existing results and is never gated |
| `HISTORICAL_DNA` | Listed for display; history is read-only and never gated |
| `GITHUB_INTEGRATION` | Starting a GitHub authorization; connecting a repository; requesting an import |

Reading what a user already has is never gated: a downgrade must not hide
data. `Entitlements::require` throws `FEATURE_NOT_INCLUDED` (402), or
`SUBSCRIPTION_INACTIVE` (402) when the user's paused or ended subscription
would include the feature, so the message can say why.

## Quotas

| Quota | Feature | Unit | Period | Consumed by |
|---|---|---|---|---|
| `ACTIVE_PROJECTS` | `PROJECTS` | count | current | Counted, not consumed: active (non-archived) projects |
| `SOURCE_UPLOADS` | `PROJECTS` | count | monthly | Each new uploaded snapshot |
| `SOURCE_UPLOAD_BYTES` | `PROJECTS` | bytes | monthly | The archive size of each new uploaded snapshot |
| `ANALYSES` | `SOURCE_ANALYSIS` | count | monthly | Each new analysis run |
| `AI_ASSESSMENTS` | `AI_ASSESSMENT` | count | monthly | Each new AI assessment |
| `CHALLENGE_SUBMISSIONS` | `CODING_CHALLENGES` | count | monthly | Each new submission |
| `GITHUB_IMPORTS` | `GITHUB_INTEGRATION` | count | monthly | Each new repository import from any provider (Phase 28: GitHub, GitLab and Bitbucket Cloud share this counter; the keys keep their Phase 19 names, the labels read "Repository imports" and "Repository integrations"). A failed import is refunded |

A limit of `null` means unlimited (none in v1); `0` means not included.
Idempotent replays (the same `Idempotency-Key`, an analysis that already
exists for the snapshot, an import already in progress) return the existing
resource and consume nothing.

### Periods

- **Monthly**, FREE: the UTC calendar month. Counters start at zero on the
  1st at 00:00 UTC.
- **Monthly**, paid plans: the subscription's current period
  (`current_period_start` to `current_period_end`). A renewal starts a new
  period with new counters.
- **Current**: no reset. `ACTIVE_PROJECTS` is the number of active projects
  now; archiving a project frees a slot.

`resets_at` in the API is the end of the period for monthly quotas and
`null` for current ones.

### Consumption

`UsageService::consume(user, quota, resourceType, resourceId, amount)` runs
inside the transaction that creates the resource:

1. `Entitlements::require` for the quota's feature.
2. Insert the counter row for (user, quota, period start) if missing, then
   lock it with `SELECT … FOR UPDATE`.
3. If `used + amount > limit`: throw `QUOTA_EXCEEDED`. The resource
   transaction rolls back; a `REJECTED` usage event is written after the
   rollback.
4. Otherwise increment `used` and append an `ACCEPTED` usage event. A unique
   partial index allows one accepted charge per (quota, resource type,
   resource ID), so a retry cannot charge twice.

`ACTIVE_PROJECTS` is enforced by `QuotaService::requireProjectSlot`, which
locks the user row and counts active projects in the project-creation
transaction, so concurrent creations cannot exceed the limit.

### Refunds

A unit is given back when the work fails for a reason on the server side.
The refund appends a `REFUNDED` event and decrements the counter, at most
once per resource:

| Resource | Refunded when it becomes |
|---|---|
| Analysis run | `FAILED` or `CANCELLED` |
| AI assessment | `FAILED` |
| Challenge submission | `ERROR` (the evaluator could not judge it; a failing solution is not refunded) |
| GitHub import | `FAILED` |

Uploads are not refunded: the snapshot exists once it is recorded.

## Downgrades and existing users

The migration creates no subscription rows and changes no existing table.
Every existing user is therefore on FREE immediately after it runs, with all
monthly counters at zero. Usage before the migration is not counted.

A user above a FREE limit (for example with five active projects, or after a
PRO subscription ends) keeps everything: all data stays readable, existing
projects keep working within the monthly quotas, and only creating more of
the over-limit resource is refused (`QUOTA_EXCEEDED`, with `used` above
`limit`). Archiving projects brings the user back under the project limit.
Nobody is locked out by the migration.

## API and UI

`GET /api/v1/billing` returns the plan that applies, the status, the period,
every entitlement and every quota with `limit`, `used`, `remaining`,
`unlimited` and `resets_at`. `/app/billing` shows the same, plus the plan
catalog. The UI makes no access decisions. When an action is refused,
the page that made the request shows the error and links to Billing. See
[API: Billing](../api/README.md#billing).
