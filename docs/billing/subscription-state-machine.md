# Subscription state machine (Phase 23)

A subscription is a user's paid plan at one payment provider. It is created
by a verified `SUBSCRIPTION_ACTIVATED` event and changes only through
provider-neutral events applied by `SubscriptionService`
(`app/Services/Billing/SubscriptionService.php`). There is no API or command
that sets a status directly. See [Billing architecture](billing-architecture.md)
for how events arrive.

## Statuses

| Status | Grants the plan | Terminal | Meaning |
|---|---|---|---|
| `TRIALING` | yes, until the period ends | no | Trial period |
| `ACTIVE` | yes, until the period ends | no | Paid and current |
| `PAST_DUE` | yes, until the period ends | no | A payment failed; the paid period still runs |
| `PAUSED` | no | no | Paused at the provider |
| `CANCELED` | no | yes | Ended by cancellation |
| `EXPIRED` | no | yes | Ended at the end of its period |

A subscription grants its plan only when its status is `TRIALING`, `ACTIVE`
or `PAST_DUE` **and** `now < current_period_end`
(`BillingSubscription::grantsAt`). Time is checked on every request, so a
period that ends stops granting at once, even before any event or the hourly
expiry job arrives. Otherwise the user is on FREE.

A user has at most one non-terminal subscription (partial unique index
`billing_subscriptions_one_current`, and a user-row lock during activation).
A second activation while one is current is `CONFLICT`.

## Transitions

```text
                SUBSCRIPTION_ACTIVATED
                (trial_ends_at in the future → TRIALING, else ACTIVE)
                          │
          ┌───────────────┴──────────────┐
          ▼                              ▼
      TRIALING ──── RENEWED ────────► ACTIVE ◄─── RENEWED ──── PAST_DUE
          │                           │  ▲  │  ── PAYMENT_FAILED ──►  │
          │                   PAUSED  │  │ RESUMED                    │
          │                           ▼  │                            │
          │                          PAUSED                           │
          │                             │                             │
          └───── CANCELED / EXPIRED ────┴───── CANCELED / EXPIRED ────┘
                                     ▼
                          CANCELED | EXPIRED  (terminal)
```

| From \ to | `TRIALING` | `ACTIVE` | `PAST_DUE` | `PAUSED` | `CANCELED` | `EXPIRED` |
|---|---|---|---|---|---|---|
| `TRIALING` | – | ✓ | ✓ | ✓ | ✓ | ✓ |
| `ACTIVE` | – | ✓ (renewal) | ✓ | ✓ | ✓ | ✓ |
| `PAST_DUE` | – | ✓ | – | ✓ | ✓ | ✓ |
| `PAUSED` | – | ✓ (resume only) | – | – | ✓ | ✓ |
| `CANCELED`, `EXPIRED` | – | – | – | – | – | – |

The table is `SubscriptionStatus::canTransitionTo`. A transition not in it is
`INVALID_TRANSITION` and changes nothing.

## Events

| Event | Effect |
|---|---|
| `SUBSCRIPTION_ACTIVATED` | Creates the subscription for a purchasable plan version with a valid period. Again for an existing subscription: `INVALID_TRANSITION` |
| `SUBSCRIPTION_RENEWED` | `ACTIVE` with the new period. The new period must be valid and must not start before the current one |
| `SUBSCRIPTION_PLAN_CHANGED` | Moves to another purchasable plan version; status unchanged. FREE, reserved and unknown plans are `UNKNOWN_PLAN` |
| `SUBSCRIPTION_CANCELED` | With `at_period_end`: sets `cancel_at_period_end`; the plan keeps granting until the period ends and the expiry job then moves it to `EXPIRED`. Without: `CANCELED` now |
| `SUBSCRIPTION_EXPIRED` | `EXPIRED` |
| `PAYMENT_FAILED` | `PAST_DUE` |
| `SUBSCRIPTION_PAUSED` | `PAUSED` |
| `SUBSCRIPTION_RESUMED` | `ACTIVE`, only from `PAUSED` |

Every applied event appends a row to `billing_subscription_events` (type,
from/to status, from/to plan, time, the webhook event that carried it) and
writes a `billing.subscription.*` log event. The history is never updated or
deleted.

## Ordering

Providers deliver events at least once and in any order. Each subscription
stores `provider_event_at`, the provider time of the newest event applied to
it. Events are decided under a lock on the subscription row:

1. **Older than `provider_event_at`**: `STALE`, ignored. A late
   `PAYMENT_FAILED` cannot undo a newer renewal, and a late renewal cannot
   revive a cancellation.
2. **Subscription terminal**: `INVALID_TRANSITION`. Nothing revives a
   canceled or expired subscription; a returning customer gets a new one.
3. **Subscription not yet known**: the event is stored as `DEFERRED`. When
   the activation arrives, deferred events are replayed in provider order
   (`occurred_at`, then arrival) and each gets its final outcome, so a
   cancellation that overtook its activation still cancels.
4. **Otherwise**: the transition table above.

Duplicates never reach this logic: the webhook claim stops them first
([Webhooks](billing-architecture.md#webhooks)).

## Expiry

`billing:expire-subscriptions` runs hourly. It moves subscriptions that were
canceled at the period end, and whose period has ended, to `EXPIRED`, and
records the history event. Access does not depend on it: the time check in
`grantsAt` already stopped granting at the period end.

## Downgrade

When a subscription stops granting, the user is on FREE at once. Nothing is
deleted: projects, snapshots, analyses, assessments and history stay readable.
Creating more is refused while the user is over a FREE limit (see
[Entitlements and quotas](entitlements-and-quotas.md#downgrades-and-existing-users)).
