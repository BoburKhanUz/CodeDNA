# Billing architecture (Phase 23)

CodeDNA's billing foundation decides, on the server, what each user may do:

```text
PLAN ──► SUBSCRIPTION ──► ENTITLEMENTS ──► USAGE / QUOTAS ──► FEATURE ACCESS
```

It is provider-neutral. No payment provider is a dependency, no payment SDK
is installed, no real credentials are needed and no money moves. A provider
is an adapter behind one interface ([Provider boundary](#provider-boundary)).
The test adapter (`fake`) exists for development and tests only.

Related documents:

- [Subscription state machine](subscription-state-machine.md): statuses,
  events, ordering and history.
- [Entitlements and quotas](entitlements-and-quotas.md): plan values, what
  each check enforces, counting, refunds and the migration.
- [API: Billing](../api/README.md#billing): endpoints and error codes.

## Principles

1. **The server decides.** Every check runs in the backend action that
   creates the resource. The browser only displays what the server returns;
   nothing it sends (a plan, a status, a quota, a header) is read.
2. **FREE is a fallback, not a row.** A user without a granting subscription
   is on FREE. No user needs a backfill, and no failure in billing can lock a
   user out of the free plan.
3. **Plans are versioned and immutable.** A subscription references one plan
   version. Changing a plan means publishing a new version.
4. **History is append-only.** Subscription events, usage events and
   webhook receipts are never updated (except the documented webhook
   outcome) or deleted. Database triggers enforce this.
5. **Money is integers.** Prices are minor units (`1500` = USD 15.00) in
   `bigint` columns. No floating point is used anywhere for money.
6. **No bypass.** There is no configuration, environment variable, header,
   route or command that grants a plan or raises a quota. The only way to a
   paid plan is a verified provider event.

## Components

| Component | Location | Responsibility |
|---|---|---|
| Plan catalog | `app/Services/Billing/Catalog/PlanCatalogV1.php`, `PlanCatalog` | The frozen v1 definitions (fingerprinted) and the lookup of stored plans |
| Context resolver | `BillingContextResolver` | The plan that applies to a user now: granting subscription or FREE, and the usage period |
| Entitlements | `Entitlements` | `allows` / `require` a feature; the single place that turns a plan into access |
| Quotas | `QuotaService`, `UsageService` | Limits, counters, consumption, refunds, the active project gauge |
| Subscriptions | `SubscriptionService` | Applies provider-neutral events; state machine; history; expiry |
| Provider boundary | `app/Services/Billing/Provider/` | `PaymentProvider` interface, `ProviderEvent`, the `fake` adapter, `PaymentProviders` |
| Webhooks | `ProcessBillingWebhook`, `BillingWebhookController` | Verify, claim, apply, record |
| API | `BillingController`, `app/Http/Resources/Billing/` | Read-only views of the caller's billing |
| Scheduler | `billing:expire-subscriptions` (hourly) | Ends subscriptions whose cancellation took effect |

## Data model

All tables are created by `2026_10_17_000001_create_billing_tables.php`. The
migration is additive: it creates tables and seeds the plan catalog, and
changes no existing table.

| Table | Contents | Mutability |
|---|---|---|
| `billing_plans` | Key, version, status, currency, integer prices, catalog fingerprint | Immutable (UPDATE and DELETE refused) |
| `billing_plan_features` | Features a plan version includes | Immutable |
| `billing_plan_quotas` | Limits per quota key (`null` = unlimited) | Immutable |
| `billing_customers` | User ↔ provider customer reference | Insert only |
| `billing_subscriptions` | Status, plan version, period, trial, cancellation, `provider_event_at` | Updated only through `SubscriptionService`; at most one non-terminal row per user (partial unique index); identity columns guarded by trigger |
| `billing_subscription_events` | Every applied transition: from/to status and plan, cause | Append only |
| `billing_webhook_events` | One row per provider event ID: normalized fields, payload SHA-256, outcome | Insert, then outcome set once (from `RECEIVED` or `DEFERRED`) |
| `billing_usage_events` | Every accepted, rejected or refunded unit, with the resource it was for | Append only; one charge per resource (unique partial index) |
| `billing_usage_counters` | Used amount per user, quota key and period | Updated under row lock; `used >= 0` (CHECK) |

Raw webhook payloads are never stored or logged, only their SHA-256.

## Request flow

A request that creates a limited resource (for example `POST
/projects/{project}/analyses`):

1. The action runs its own validation and authorization first (policy, project
   state, idempotent replay). A replay of an existing resource is answered
   without touching billing.
2. Inside the transaction that creates the resource, `UsageService::consume`
   resolves the billing context, calls `Entitlements::require` for the
   quota's feature, locks the counter row (`SELECT … FOR UPDATE`), checks
   the limit, increments the counter and appends an `ACCEPTED` usage event
   keyed by the resource.
3. Over the limit, it throws `QUOTA_EXCEEDED` (402). The transaction rolls
   back, so the resource is not created, and a `REJECTED` usage event is
   recorded after the rollback.
4. If the work later fails for reasons that are not the user's (an analysis,
   AI assessment, challenge evaluation or GitHub import that fails or is
   cancelled), the unit is refunded once (`REFUNDED` event, counter
   decremented).

Expensive work that happens before the resource row exists (storing an
uploaded archive) calls `ensureAvailable` first, so an over-quota upload is
refused before it is written to storage. The authoritative check is still the
locked one in step 2.

## Errors

| HTTP | Code | Meaning | `details` |
|---|---|---|---|
| 402 | `FEATURE_NOT_INCLUDED` | The caller's plan does not include the feature | `feature`, `plan` |
| 402 | `SUBSCRIPTION_INACTIVE` | The caller has a subscription whose plan would include it, but it does not grant access now (paused, ended) | `feature`, `plan` |
| 402 | `QUOTA_EXCEEDED` | The request would exceed a limit | `quota`, `limit`, `used`, `resets_at` |
| 503 | `BILLING_UNAVAILABLE` | Billing could not be decided (for example, the plan catalog is missing) | none |

A billing refusal never creates anything and never consumes anything. The
details carry no provider references.

## Webhooks

`POST /api/v1/billing/webhooks/{provider}` is the only way subscription state
changes. It has no session authentication; the provider's signature is the
authentication.

1. **Provider.** Only the configured provider (`BILLING_PROVIDER`) is
   accepted. Any other name, or `none`, is `404`.
2. **Size.** Bodies over `webhook_max_bytes` (64 KiB) are refused with `413`
   before they are read or parsed.
3. **Signature.** The adapter verifies the signature over the raw body, with
   a constant-time comparison and a timestamp tolerance (300 s), before
   anything is parsed or stored. Failure is `400` and is logged without the
   body or the signature.
4. **Parse.** The adapter translates the provider's event into a
   provider-neutral `ProviderEvent`. Unknown types or malformed fields are
   `400`.
5. **Claim.** One row per `(provider, provider_event_id)`, inserted with
   `INSERT … ON CONFLICT DO NOTHING`. A redelivery, even a concurrent one,
   finds the row taken and answers `200` with `status: duplicate` and the
   original outcome. Nothing is applied twice.
6. **Apply.** `SubscriptionService::apply` decides the event under a lock on
   the subscription row ([ordering](subscription-state-machine.md#ordering)).
7. **Record.** The outcome is stored once on the webhook row.

Every verified event is answered `200`, whatever its outcome, so a provider
does not retry an event that was decided. The response is:

```json
{ "data": { "type": "billing_webhook_receipt", "status": "processed", "outcome": "APPLIED" } }
```

| Outcome | Meaning |
|---|---|
| `APPLIED` | The event changed the subscription and was recorded in its history |
| `STALE` | Older than the newest event already applied to the subscription; ignored |
| `INVALID_TRANSITION` | Not allowed from the current status (for example, anything after a terminal status) |
| `DEFERRED` | The subscription's activation has not arrived yet; applied in order when it does |
| `UNKNOWN_CUSTOMER` | No CodeDNA user has this provider customer reference |
| `UNKNOWN_SUBSCRIPTION` | The subscription belongs to a different customer |
| `UNKNOWN_PLAN` | The plan is not purchasable (unknown, FREE, reserved or retired) |
| `CONFLICT` | The user already has a current subscription |

**Replay.** Replaying a stored event is a redelivery: it is a duplicate and
changes nothing. Re-processing after a fix means the provider sends the event
again with a new event ID.

### The `fake` provider

For development and tests only; the configuration validator refuses it in
every deployed environment (`production`, `staging`, `prod`, `demo`, …).

- Header: `X-Billing-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256>`.
- Signed string: `"<t>.<raw body>"`, keyed with `BILLING_WEBHOOK_SECRET`
  (at least 32 characters).
- Body: `{"id", "type", "occurred_at", "customer", "subscription", "plan":
  {"key", "version"?}, "period": {"start", "end"}?, "trial_ends_at"?,
  "at_period_end"?}`, with `type` one of the provider-neutral event types.

## Provider boundary

`App\Services\Billing\Provider\PaymentProvider`:

| Method | Purpose |
|---|---|
| `name()`, `supports(ProviderCapability)` | Identity and optional features |
| `createCustomer(User)` | Returns the provider's customer reference |
| `createCheckoutSession(…)` | A hosted checkout URL (not used by the UI yet) |
| `cancelSubscription`, `changeSubscription`, `getSubscription` | Outbound management |
| `verifyWebhook(string $body, array $headers)` | Signature and timestamp check on the raw body |
| `parseWebhook(string $body)` | Translation into a `ProviderEvent` |

Adding a real provider means adding one adapter class and registering it in
`PaymentProviders`. The billing domain, the database and the API stay the
same. Adapters must not log payloads, signatures or secrets.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `BILLING_PROVIDER` | `none` | `none` (webhooks disabled) or the name of an adapter (`fake` outside production) |
| `BILLING_WEBHOOK_SECRET` | empty | Webhook signing secret, at least 32 characters when a provider is configured |

These are the only billing variables. Limits are fixed in the code
(`webhook_tolerance_seconds` 300, `webhook_max_bytes` 65536) and the rate
limits are `billing_read_per_minute` (60, per user) and
`billing_webhook_per_minute` (600, per provider and IP).

## Observability

Structured log events, without payloads, signatures, secrets or provider
references beyond the event ID:

- `billing.webhook.received`, `.duplicate`, `.rejected` (with `reason`),
  `.not_applied` (with `outcome`) and `.replayed`.
- `billing.subscription.activated`, `.renewed`, `.plan_changed`,
  `.canceled`, `.expired`, `.paused`, `.resumed`, `.conflict`; and
  `billing.payment.failed`.
- `billing.feature.denied`, `billing.quota.exceeded` and
  `billing.usage.refunded`.

## Security

| # | Threat | Mitigation |
|---|---|---|
| A | Granting Pro from the browser | No write route; requests carrying `plan`, `status` or quota fields are `405`; nothing client-sent is read |
| B | Forged webhook | HMAC over the raw body, constant-time comparison, verified before parsing |
| C | Replayed webhook | Timestamp tolerance, plus one row per provider event ID |
| D | Concurrent duplicate delivery | Unique claim; the losing insert changes nothing |
| E | Out-of-order events | `provider_event_at` ordering, terminal states final, deferred events replayed in order |
| F | Quota race (two requests, one unit left) | Counter row locked inside the creating transaction |
| G | Project limit race | User row locked while counting active projects |
| H | Double charge on retry | One `ACCEPTED` event per resource (unique index); idempotent replays skip billing |
| I | Refund abuse | Refunds only on server-side failure transitions, once per resource |
| J | Tampering with history | Triggers refuse UPDATE on immutable rows; models refuse deletes |
| K | Debug or configuration bypass | No `FORCE_PRO`-style setting; a test checks the only billing variables are the provider and its secret |
| L | Test provider in production | The configuration validator refuses `fake` in deployed environments |
| M | Leaking provider references or secrets | Resources omit provider references; logs carry no payload, signature or secret |
| N | Oversized or slow webhook bodies | 64 KiB limit before reading; per-IP rate limit |

## Not implemented

- A real payment provider, checkout UI, invoices, taxes, coupons, refunds of
  money, proration and dunning emails.
- Self-service plan changes or cancellation from CodeDNA (they happen at the
  provider and arrive as webhooks).
- Team payments. Since Phase 24 an organization is a billing subject with a
  plan reference and seats ([team billing boundary](../teams/billing-boundary.md)),
  but nothing can be bought for it; `TEAM_READY` stays reserved.
- An admin UI for billing.
