<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionEventType;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Billing\WebhookOutcome;
use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionEvent;
use App\Models\BillingWebhookEvent;
use App\Models\User;
use App\Services\Billing\Provider\PaymentProvider;
use App\Services\Billing\Provider\ProviderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only code that changes subscriptions (docs/billing/subscription-state-machine.md).
 *
 * Applies provider-neutral events under the subscription's row lock:
 *
 * - an event older than the last one applied (provider time) is STALE and
 *   changes nothing, so out-of-order delivery never reverts newer state;
 * - an event that arrives before its subscription's activation is DEFERRED,
 *   then replayed in provider order right after the activation;
 * - a terminal subscription (CANCELED, EXPIRED) never changes again;
 * - transitions follow SubscriptionStatus::canTransitionTo();
 * - every applied change appends a history row.
 */
final readonly class SubscriptionService
{
    public function __construct(private PlanCatalog $catalog) {}

    /**
     * The user's customer at a provider, created once (internal: no API takes a customer reference).
     */
    public function customerFor(User $user, PaymentProvider $provider): BillingCustomer
    {
        $existing = BillingCustomer::query()->where('user_id', $user->getKey())->where('provider', $provider->name())->first();
        if ($existing !== null) {
            return $existing;
        }
        $customer = new BillingCustomer;
        $customer->forceFill(['user_id' => $user->getKey(), 'provider' => $provider->name(), 'provider_customer_ref' => $provider->createCustomer($user)]);
        try {
            $customer->save();
        } catch (UniqueConstraintViolationException) {
            return BillingCustomer::query()->where('user_id', $user->getKey())->where('provider', $provider->name())->firstOrFail();
        }

        return $customer;
    }

    /**
     * Applies one event. Runs in its own transaction (or the caller's).
     *
     * @param  string|null  $webhookEventId  the webhook event that carried it, for the history
     * @return array{WebhookOutcome, BillingSubscription|null}
     */
    public function apply(ProviderEvent $event, ?string $webhookEventId = null): array
    {
        return DB::transaction(function () use ($event, $webhookEventId): array {
            $customer = BillingCustomer::query()->where('provider', $event->provider)->where('provider_customer_ref', $event->customerRef)->first();
            if ($customer === null) {
                return [WebhookOutcome::UnknownCustomer, null];
            }
            $subscription = BillingSubscription::query()
                ->where('provider', $event->provider)->where('provider_subscription_ref', $event->subscriptionRef)
                ->lockForUpdate()->first();

            if ($subscription === null) {
                // An event that overtook its subscription's activation waits for it.
                return $event->type === SubscriptionEventType::Activated
                    ? $this->activate($customer, $event, $webhookEventId)
                    : [WebhookOutcome::Deferred, null];
            }
            if ($subscription->user_id !== $customer->user_id) {
                return [WebhookOutcome::UnknownSubscription, null];
            }
            if ($event->occurredAt->lessThan($subscription->provider_event_at)) {
                return [WebhookOutcome::Stale, $subscription];
            }
            if ($subscription->status->isTerminal()) {
                return [WebhookOutcome::InvalidTransition, $subscription];
            }

            return [$this->transition($subscription, $event, $webhookEventId), $subscription];
        });
    }

    /**
     * Ends subscriptions whose cancellation took effect at the end of their
     * period (scheduled; access already stopped at the period end).
     */
    public function expireEnded(Carbon $now): int
    {
        $expired = 0;
        $candidates = BillingSubscription::query()
            ->whereIn('status', SubscriptionStatus::nonTerminalValues())
            ->where('cancel_at_period_end', true)->where('current_period_end', '<=', $now)
            ->pluck('id');
        foreach ($candidates as $id) {
            $expired += (int) DB::transaction(function () use ($id, $now): bool {
                $subscription = BillingSubscription::query()->whereKey($id)->lockForUpdate()->first();
                if ($subscription === null || $subscription->status->isTerminal() || ! $subscription->cancel_at_period_end
                    || $subscription->current_period_end->greaterThan($now)) {
                    return false;
                }
                $from = $subscription->status;
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Expired, 'ended_at' => $subscription->current_period_end,
                    'provider_event_at' => $subscription->provider_event_at->max($subscription->current_period_end),
                ])->save();
                $this->history($subscription, SubscriptionEventType::Expired, $from, $subscription->billing_plan_id, $subscription->current_period_end, null);

                return true;
            });
        }

        return $expired;
    }

    /**
     * @return array{WebhookOutcome, BillingSubscription|null}
     */
    private function activate(BillingCustomer $customer, ProviderEvent $event, ?string $webhookEventId): array
    {
        $plan = $event->planKey === null ? null : $this->catalog->purchasable($event->planKey, $event->planVersion);
        if ($plan === null) {
            return [WebhookOutcome::UnknownPlan, null];
        }
        if ($event->periodStart === null || $event->periodEnd === null || ! $event->periodEnd->greaterThan($event->periodStart)) {
            return [WebhookOutcome::InvalidTransition, null];
        }
        // Lock the user, so two activations for different subscriptions are decided one at a time.
        User::query()->whereKey($customer->user_id)->lockForUpdate()->firstOrFail();
        $current = BillingSubscription::query()->where('user_id', $customer->user_id)
            ->whereIn('status', SubscriptionStatus::nonTerminalValues())->exists();
        if ($current) {
            Log::warning('billing.subscription.conflict', ['user_id' => $customer->user_id, 'provider' => $event->provider]);

            return [WebhookOutcome::Conflict, null];
        }

        $trialing = $event->trialEndsAt !== null && $event->trialEndsAt->greaterThan($event->occurredAt);
        $subscription = new BillingSubscription;
        $subscription->forceFill([
            'user_id' => $customer->user_id,
            'billing_customer_id' => $customer->id,
            'billing_plan_id' => $plan->id,
            'provider' => $event->provider,
            'provider_subscription_ref' => $event->subscriptionRef,
            'status' => $trialing ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'started_at' => $event->occurredAt->min($event->periodStart),
            'current_period_start' => $event->periodStart,
            'current_period_end' => $event->periodEnd,
            'trial_ends_at' => $trialing ? $event->trialEndsAt : null,
            'cancel_at_period_end' => false,
            'provider_event_at' => $event->occurredAt,
        ])->save();
        $this->history($subscription, $event->type, null, null, $event->occurredAt, $webhookEventId);
        $this->replayDeferred($subscription);

        return [WebhookOutcome::Applied, $subscription];
    }

    /**
     * Applies, in provider order, the events of this subscription that
     * arrived before its activation. Each is decided once: an event older
     * than the activation is STALE; the rest are applied or refused like
     * any other event, so the final state is the one of the newest event.
     */
    private function replayDeferred(BillingSubscription $subscription): void
    {
        $deferred = BillingWebhookEvent::query()
            ->where('provider', $subscription->provider)->where('provider_subscription_ref', $subscription->provider_subscription_ref)
            ->where('outcome', WebhookOutcome::Deferred->value)
            ->orderBy('occurred_at')->orderBy('received_at')->orderBy('id')
            ->lockForUpdate()->get();
        foreach ($deferred as $row) {
            $event = new ProviderEvent(
                provider: $row->provider, eventId: $row->provider_event_id, type: $row->event_type, occurredAt: $row->occurred_at,
                customerRef: $row->provider_customer_ref, subscriptionRef: $row->provider_subscription_ref,
                planKey: $row->plan_key, planVersion: $row->plan_version, periodStart: $row->period_start, periodEnd: $row->period_end,
                trialEndsAt: $row->trial_ends_at, atPeriodEnd: $row->at_period_end,
            );
            $outcome = match (true) {
                $event->occurredAt->lessThan($subscription->provider_event_at) => WebhookOutcome::Stale,
                $subscription->status->isTerminal() => WebhookOutcome::InvalidTransition,
                default => $this->transition($subscription, $event, $row->id),
            };
            $row->forceFill(['outcome' => $outcome, 'billing_subscription_id' => $subscription->id, 'processed_at' => Carbon::now()])->save();
            Log::info('billing.webhook.replayed', ['webhook_event_id' => $row->id, 'subscription_id' => $subscription->id, 'outcome' => $outcome->value]);
        }
    }

    private function transition(BillingSubscription $subscription, ProviderEvent $event, ?string $webhookEventId): WebhookOutcome
    {
        $fromStatus = $subscription->status;
        $fromPlan = $subscription->billing_plan_id;
        $changes = ['provider_event_at' => $event->occurredAt];

        switch ($event->type) {
            case SubscriptionEventType::Activated:
                return WebhookOutcome::InvalidTransition;
            case SubscriptionEventType::Renewed:
                if ($event->periodStart === null || $event->periodEnd === null || ! $event->periodEnd->greaterThan($event->periodStart)
                    || $event->periodStart->lessThan($subscription->current_period_start)) {
                    return WebhookOutcome::InvalidTransition;
                }
                $changes += ['status' => SubscriptionStatus::Active, 'current_period_start' => $event->periodStart, 'current_period_end' => $event->periodEnd];
                break;
            case SubscriptionEventType::PlanChanged:
                $plan = $event->planKey === null ? null : $this->catalog->purchasable($event->planKey, $event->planVersion);
                if (! $plan instanceof BillingPlan) {
                    return WebhookOutcome::UnknownPlan;
                }
                $changes['billing_plan_id'] = $plan->id;
                break;
            case SubscriptionEventType::Canceled:
                $changes += $event->atPeriodEnd
                    ? ['cancel_at_period_end' => true, 'canceled_at' => $event->occurredAt]
                    : ['status' => SubscriptionStatus::Canceled, 'canceled_at' => $event->occurredAt, 'ended_at' => $event->occurredAt];
                break;
            case SubscriptionEventType::Expired:
                $changes += ['status' => SubscriptionStatus::Expired, 'ended_at' => $event->occurredAt];
                break;
            case SubscriptionEventType::PaymentFailed:
                $changes['status'] = SubscriptionStatus::PastDue;
                break;
            case SubscriptionEventType::Paused:
                $changes['status'] = SubscriptionStatus::Paused;
                break;
            case SubscriptionEventType::Resumed:
                if ($fromStatus !== SubscriptionStatus::Paused) {
                    return WebhookOutcome::InvalidTransition;
                }
                $changes['status'] = SubscriptionStatus::Active;
                break;
        }

        $toStatus = $changes['status'] ?? $fromStatus;
        if ($toStatus !== $fromStatus && ! $fromStatus->canTransitionTo($toStatus)) {
            return WebhookOutcome::InvalidTransition;
        }
        $subscription->forceFill($changes)->save();
        $this->history($subscription, $event->type, $fromStatus, $fromPlan, $event->occurredAt, $webhookEventId);

        return WebhookOutcome::Applied;
    }

    private function history(BillingSubscription $subscription, SubscriptionEventType $type, ?SubscriptionStatus $from, ?string $fromPlan, Carbon $occurredAt, ?string $webhookEventId): void
    {
        $event = new BillingSubscriptionEvent;
        $event->forceFill([
            'billing_subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $subscription->status,
            'from_plan_id' => $fromPlan,
            'to_plan_id' => $subscription->billing_plan_id,
            'billing_webhook_event_id' => $webhookEventId,
            'occurred_at' => $occurredAt,
        ])->save();
        Log::info($type->logName(), [
            'user_id' => $subscription->user_id, 'subscription_id' => $subscription->id, 'provider' => $subscription->provider,
            'from_status' => $from?->value, 'to_status' => $subscription->status->value, 'webhook_event_id' => $webhookEventId,
        ]);
    }
}
