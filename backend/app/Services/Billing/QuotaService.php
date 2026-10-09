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
use App\Services\Enterprise\EnterpriseEdition;
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
    public function __construct(private Entitlements $entitlements, private UsageService $usage, private EnterpriseEdition $edition) {}

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
        // Read-only: every monthly counter of the period in one query (Phase 26;
        // it was one query per quota). Decisions that consume a quota still
        // read and lock their own counter row (UsageService).
        $monthly = array_values(array_filter(QuotaKey::cases(), static fn (QuotaKey $key): bool => $key->period() !== QuotaPeriod::Current));
        $counters = DB::table($context->counterTable())->where($context->subject())
            ->where('period_start', $context->periodStart)
            ->whereIn('quota_key', array_map(static fn (QuotaKey $key): string => $key->value, $monthly))
            ->pluck('used', 'quota_key')->all();

        return array_map(function (QuotaKey $key) use ($context, $counters): array {
            $limit = $this->limit($context, $key);
            $used = $key->period() === QuotaPeriod::Current ? $this->used($context, $key) : (int) ($counters[$key->value] ?? 0);

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

    /**
     * The organization's seat entitlement (its account's, or a valid
     * license's when higher) and the seats in use (ACTIVE memberships). A caller that already read the billing account passes it.
     */
    public function seats(Organization $organization, ?OrganizationBillingAccount $account = null): array
    {
        $account ??= OrganizationBillingAccount::query()->find($organization->getKey())
            ?? throw new ApiException(ErrorCode::BillingUnavailable);
        $used = OrganizationMembership::query()->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active->value)->count();

        // A valid enterprise license (Phase 27) raises every organization's
        // seat limit to its own; it never lowers one.
        $limit = max($account->seat_limit, $this->edition->organizationSeats() ?? 0);

        return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
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
