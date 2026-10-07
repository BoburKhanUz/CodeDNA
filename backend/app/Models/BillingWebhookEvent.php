<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\SubscriptionEventType;
use App\Enums\Billing\WebhookOutcome;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One verified provider event, by provider event ID (Phase 23): the
 * idempotency key and audit record of webhook processing. Decided once
 * (RECEIVED or DEFERRED -> final outcome), never deleted. The raw payload
 * is not stored, only its SHA-256 and the normalized event.
 *
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property SubscriptionEventType $event_type
 * @property Carbon $occurred_at
 * @property string $provider_customer_ref
 * @property string $provider_subscription_ref
 * @property string|null $plan_key
 * @property string|null $plan_version
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $trial_ends_at
 * @property bool $at_period_end
 * @property string $payload_sha256
 * @property WebhookOutcome $outcome
 * @property string|null $billing_subscription_id
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
class BillingWebhookEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static function (self $event): void {
            if (! in_array(WebhookOutcome::from((string) $event->getRawOriginal('outcome')), [WebhookOutcome::Received, WebhookOutcome::Deferred], true)) {
                throw DomainRuleViolation::immutable($event, 'updated');
            }
        });
        static::deleting(static fn (self $event) => throw DomainRuleViolation::immutable($event, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => SubscriptionEventType::class,
            'outcome' => WebhookOutcome::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'at_period_end' => 'boolean',
        ];
    }
}
