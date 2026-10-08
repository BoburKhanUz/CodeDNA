<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\OrganizationBillingAccount;
use App\Models\Project;
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
 * Since Phase 24 an organization is a billing subject too
 * (docs/teams/billing-boundary.md): its team projects are measured against
 * the plan its billing account names, per UTC calendar month. A project's
 * subject is its organization when it has one, otherwise its owner; the
 * two never share counters.
 *
 * Nothing a client sends takes part in this decision.
 */
final readonly class BillingContextResolver
{
    public function __construct(private PlanCatalog $catalog) {}

    /**
     * The context of whoever pays for this: a user, an organization, or a
     * project's subject (its organization, or its owner when personal).
     */
    public function resolveSubject(User|string|Project|Organization $subject, ?Carbon $now = null): BillingContext
    {
        return match (true) {
            $subject instanceof Organization => $this->resolveOrganization((string) $subject->getKey(), $now),
            $subject instanceof Project => $subject->organization_id !== null
                ? $this->resolveOrganization($subject->organization_id, $now)
                : $this->resolve($subject->user_id, $now),
            default => $this->resolve($subject, $now),
        };
    }

    public function resolveOrganization(string $organizationId, ?Carbon $now = null): BillingContext
    {
        $now = ($now ?? Carbon::now())->copy()->utc();
        $account = OrganizationBillingAccount::query()->find($organizationId)
            ?? throw new ApiException(ErrorCode::BillingUnavailable);

        return new BillingContext(null, $this->catalog->byId($account->billing_plan_id), null, null,
            $now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addMonthNoOverflow(), $now, $organizationId);
    }

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
