<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\SubscriptionEventType;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One applied change of a subscription: the history (Phase 23). Append-only.
 *
 * @property string $id
 * @property string $billing_subscription_id
 * @property string $user_id
 * @property SubscriptionEventType $type
 * @property SubscriptionStatus|null $from_status
 * @property SubscriptionStatus $to_status
 * @property string|null $from_plan_id
 * @property string $to_plan_id
 * @property string|null $billing_webhook_event_id
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read BillingPlan $toPlan
 */
class BillingSubscriptionEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $event) => throw DomainRuleViolation::immutable($event, 'updated'));
        static::deleting(static fn (self $event) => throw DomainRuleViolation::immutable($event, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SubscriptionEventType::class,
            'from_status' => SubscriptionStatus::class,
            'to_status' => SubscriptionStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingPlan, $this>
     */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'to_plan_id');
    }
}
