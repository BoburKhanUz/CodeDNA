<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

use App\Enums\Billing\SubscriptionEventType;
use Illuminate\Support\Carbon;

/**
 * A provider-neutral subscription event, as translated by a provider adapter
 * from its own webhook. The billing domain applies only these.
 */
final readonly class ProviderEvent
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public SubscriptionEventType $type,
        public Carbon $occurredAt,
        public string $customerRef,
        public string $subscriptionRef,
        public ?string $planKey = null,
        public ?string $planVersion = null,
        public ?Carbon $periodStart = null,
        public ?Carbon $periodEnd = null,
        public ?Carbon $trialEndsAt = null,
        public bool $atPeriodEnd = false,
    ) {}
}
