<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A billing subject's effective commercial state at one moment: the plan
 * that applies, the subscription granting it (null on the free fallback), a
 * current subscription that exists but grants nothing right now (lapsed),
 * and the period monthly quotas are counted in.
 *
 * The subject is a user (personal projects) or, since Phase 24, an
 * organization (its team projects); exactly one of userId and
 * organizationId is set (docs/teams/billing-boundary.md). Organizations
 * have no subscriptions yet.
 */
final readonly class BillingContext
{
    public function __construct(
        public ?string $userId,
        public BillingPlan $plan,
        public ?BillingSubscription $subscription,
        public ?BillingSubscription $lapsed,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public Carbon $now,
        public ?string $organizationId = null,
    ) {
        if (($userId === null) === ($organizationId === null)) {
            throw new LogicException('A billing context has exactly one subject: a user or an organization.');
        }
    }

    public function isFreeFallback(): bool
    {
        return $this->subscription === null;
    }

    public function isOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    /**
     * The subject as the column that identifies it, e.g. ['user_id' => '01…'].
     *
     * @return array{user_id: string}|array{organization_id: string}
     */
    public function subject(): array
    {
        return $this->organizationId !== null ? ['organization_id' => $this->organizationId] : ['user_id' => (string) $this->userId];
    }

    /** The counter table of the subject's monthly quotas. */
    public function counterTable(): string
    {
        return $this->organizationId !== null ? 'billing_organization_usage_counters' : 'billing_usage_counters';
    }
}
