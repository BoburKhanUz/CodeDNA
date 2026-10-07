<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One subscription at a payment provider (Phase 23,
 * docs/billing/subscription-state-machine.md). Changed only by
 * App\Services\Billing\SubscriptionService, which records every change in
 * billing_subscription_events. A terminal subscription never changes, and
 * none is deleted (model and trigger).
 *
 * @property string $id
 * @property string $user_id
 * @property string $billing_customer_id
 * @property string $billing_plan_id
 * @property string $provider
 * @property string $provider_subscription_ref
 * @property SubscriptionStatus $status
 * @property Carbon $started_at
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property Carbon|null $trial_ends_at
 * @property bool $cancel_at_period_end
 * @property Carbon|null $canceled_at
 * @property Carbon|null $ended_at
 * @property Carbon $provider_event_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read BillingPlan $plan
 */
class BillingSubscription extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        static::updating(static function (self $subscription): void {
            if (SubscriptionStatus::from((string) $subscription->getRawOriginal('status'))->isTerminal()) {
                throw DomainRuleViolation::immutable($subscription, 'updated');
            }
        });
        static::deleting(static fn (self $subscription) => throw DomainRuleViolation::immutable($subscription, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'provider_event_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    /** Whether the subscription grants its plan at this moment. */
    public function grantsAt(Carbon $now): bool
    {
        return $this->status->canGrant() && $now->lessThan($this->current_period_end);
    }
}
