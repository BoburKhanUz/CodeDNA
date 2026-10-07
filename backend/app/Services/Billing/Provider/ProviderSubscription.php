<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

use App\Enums\Billing\SubscriptionStatus;
use Illuminate\Support\Carbon;

/** A provider's view of a subscription, in provider-neutral terms (for reconciliation). */
final readonly class ProviderSubscription
{
    public function __construct(
        public string $subscriptionRef,
        public string $customerRef,
        public SubscriptionStatus $status,
        public string $planKey,
        public Carbon $currentPeriodEnd,
    ) {}
}
