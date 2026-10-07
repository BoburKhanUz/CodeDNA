<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\Feature;
use App\Enums\Billing\QuotaKey;
use App\Enums\Billing\QuotaPeriod;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Quota limits and usage (docs/billing/entitlements-and-quotas.md#quotas).
 *
 * Monthly quotas are read from billing_usage_counters for the context's
 * period; the ACTIVE_PROJECTS gauge counts the user's active projects.
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
            return Project::query()->where('user_id', $context->userId)->where('status', ProjectStatus::Active->value)->count();
        }

        return (int) DB::table('billing_usage_counters')
            ->where('user_id', $context->userId)->where('quota_key', $key->value)->where('period_start', $context->periodStart)
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
     * locks the user row, so concurrent creations are counted one at a time.
     *
     * @throws ApiException FEATURE_NOT_INCLUDED, SUBSCRIPTION_INACTIVE, QUOTA_EXCEEDED
     */
    public function requireProjectSlot(User $user): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('requireProjectSlot() must run inside the transaction that creates the project.');
        }
        User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
        $context = $this->entitlements->require($user, Feature::Projects);
        $limit = $this->limit($context, QuotaKey::ActiveProjects);
        $used = $this->used($context, QuotaKey::ActiveProjects);
        if ($limit !== null && $used + 1 > $limit) {
            $this->usage->refuse($context, QuotaKey::ActiveProjects, 1, $limit, $used);
        }
    }
}
