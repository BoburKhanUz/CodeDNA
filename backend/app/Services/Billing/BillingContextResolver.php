<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Every user has an effective billing context (docs/billing/billing-architecture.md#free-fallback):
 *
 * - their current subscription's plan, while it grants (TRIALING, ACTIVE or
 *   PAST_DUE, and before its current period ends);
 * - otherwise the newest FREE plan, by deterministic fallback, with monthly
 *   quotas counted per UTC calendar month.
 *
 * Nothing a client sends takes part in this decision.
 */
final readonly class BillingContextResolver
{
    public function __construct(private PlanCatalog $catalog) {}

    public function resolve(User|string $user, ?Carbon $now = null): BillingContext
    {
        $userId = $user instanceof User ? (string) $user->getKey() : $user;
        $now = ($now ?? Carbon::now())->copy()->utc();
        $current = BillingSubscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', SubscriptionStatus::nonTerminalValues())
            ->with(['plan.features', 'plan.quotas'])
            ->first();

        if ($current !== null && $current->grantsAt($now)) {
            return new BillingContext($userId, $current->plan, $current, null,
                $current->current_period_start->copy()->utc(), $current->current_period_end->copy()->utc(), $now);
        }

        return new BillingContext($userId, $this->catalog->free(), null, $current,
            $now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addMonthNoOverflow(), $now);
    }
}
