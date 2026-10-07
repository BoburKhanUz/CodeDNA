<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use Illuminate\Support\Carbon;

/**
 * A user's effective commercial state at one moment: the plan that applies,
 * the subscription granting it (null on the free fallback), a current
 * subscription that exists but grants nothing right now (lapsed), and the
 * period monthly quotas are counted in.
 */
final readonly class BillingContext
{
    public function __construct(
        public string $userId,
        public BillingPlan $plan,
        public ?BillingSubscription $subscription,
        public ?BillingSubscription $lapsed,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public Carbon $now,
    ) {}

    public function isFreeFallback(): bool
    {
        return $this->subscription === null;
    }
}
