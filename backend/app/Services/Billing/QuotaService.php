<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\Feature;
use App\Enums\Billing\QuotaKey;
use App\Enums\Billing\QuotaPeriod;
use App\Enums\Organizations\MembershipStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Organization;
use App\Models\OrganizationBillingAccount;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Quota limits and usage (docs/billing/entitlements-and-quotas.md#quotas).
 *
 * Monthly quotas are read from the subject's counters for the context's
 * period; the ACTIVE_PROJECTS gauge counts the subject's active projects:
 * a user's personal projects, or an organization's team projects, never
 * both (docs/teams/billing-boundary.md). Seats (Phase 24) are an
 * organization's ACTIVE memberships.
 */
final readonly class QuotaService
{
    public function __construct(private Entitlements $entitlements, private UsageService $usage) {}

    public function limit(BillingContext $context, QuotaKey $key): ?int
    {
        return $context->plan->includes($key->feature()) ? $context->plan->limitFor($key) : 0;
    }

    public function used(BillingContext $context, QuotaKey $key): int
    {
        if ($key->period() === QuotaPeriod::Current) {
            $projects = $context->organizationId !== null
                ? Project::query()->where('organization_id', $context->organizationId)
                : Project::query()->where('user_id', $context->userId)->whereNull('organization_id');

            return $projects->where('status', ProjectStatus::Active->value)->count();
        }

        return (int) DB::table($context->counterTable())->where($context->subject())
            ->where('quota_key', $key->value)->where('period_start', $context->periodStart)
            ->value('used');
    }

    /**
     * Every quota of the context, in catalog order.
     *
     * @return list<array{key: QuotaKey, limit: int|null, used: int, remaining: int|null, resets_at: string|null}>
     */
    public function summary(BillingContext $context): array
    {
        return array_map(function (QuotaKey $key) use ($context): array {
            $limit = $this->limit($context, $key);
            $used = $this->used($context, $key);

            return [
                'key' => $key,
                'limit' => $limit,
                'used' => $used,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'resets_at' => $key->period() === QuotaPeriod::Monthly ? $context->periodEnd->toIso8601ZuluString() : null,
            ];
        }, QuotaKey::cases());
    }

    /**
     * A project may be created: the PROJECTS feature, and fewer active
     * projects than the plan allows. Runs inside the caller's transaction and
     * locks the subject's row (the user, or the organization for a team
     * project), so concurrent creations are counted one at a time.
     *
     * @throws ApiException FEATURE_NOT_INCLUDED, SUBSCRIPTION_INACTIVE, QUOTA_EXCEEDED
     */
    public function requireProjectSlot(User|Organization $subject): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('requireProjectSlot() must run inside the transaction that creates the project.');
        }
        $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
        $context = $this->entitlements->require($subject, Feature::Projects);
        $limit = $this->limit($context, QuotaKey::ActiveProjects);
        $used = $this->used($context, QuotaKey::ActiveProjects);
        if ($limit !== null && $used + 1 > $limit) {
            $this->usage->refuse($context, QuotaKey::ActiveProjects, 1, $limit, $used);
        }
    }

    /** The organization's seat entitlement and the seats in use (ACTIVE memberships). */
    public function seats(Organization $organization): array
    {
        $account = OrganizationBillingAccount::query()->find($organization->getKey())
            ?? throw new ApiException(ErrorCode::BillingUnavailable);
        $used = OrganizationMembership::query()->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active->value)->count();

        return ['limit' => $account->seat_limit, 'used' => $used, 'remaining' => max(0, $account->seat_limit - $used)];
    }

    /**
     * One more ACTIVE membership fits the organization's seat entitlement.
     * The caller holds the organization row lock in the transaction that
     * activates the membership, so concurrent acceptances and reactivations
     * take the last seat one at a time.
     *
     * @throws ApiException SEAT_LIMIT_REACHED
     */
    public function requireSeat(Organization $organization): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('requireSeat() must run inside the transaction that activates the membership.');
        }
        $seats = $this->seats($organization);
        if ($seats['limit'] < $seats['used'] + 1) {
            Log::info('organization.seat_limit_reached', ['organization_id' => $organization->getKey(), 'limit' => $seats['limit'], 'used' => $seats['used']]);

            throw new ApiException(ErrorCode::SeatLimitReached, null, ['limit' => $seats['limit'], 'used' => $seats['used']]);
        }
    }
}
