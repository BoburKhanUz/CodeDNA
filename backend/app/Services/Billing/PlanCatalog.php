<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\PlanStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\BillingPlan;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads the server-owned plan catalog (immutable plan rows seeded from the
 * frozen catalog definitions). Clients never choose or change plans; plan
 * rows are found here by key and version for provider events only.
 */
final class PlanCatalog
{
    public const FREE = 'FREE';

    /** @var Collection<int, BillingPlan>|null */
    private ?Collection $plans = null;

    /**
     * @return Collection<int, BillingPlan>
     */
    private function all(): Collection
    {
        return $this->plans ??= BillingPlan::query()->with(['features', 'quotas'])->orderBy('key')->get();
    }

    /** The newest version of a plan key with the given status, or null. */
    private function newest(string $key, PlanStatus ...$statuses): ?BillingPlan
    {
        return $this->all()
            ->filter(fn (BillingPlan $p): bool => $p->key === $key && in_array($p->status, $statuses, true))
            ->sort(fn (BillingPlan $a, BillingPlan $b): int => version_compare($b->version, $a->version))
            ->first();
    }

    /** The plan every user without a granting subscription is on. */
    public function free(): BillingPlan
    {
        return $this->newest(self::FREE, PlanStatus::Active)
            ?? throw new ApiException(ErrorCode::BillingUnavailable);
    }

    /** A plan version a provider may subscribe a user to: only ACTIVE plans other than FREE. */
    public function purchasable(string $key, ?string $version): ?BillingPlan
    {
        if ($key === self::FREE) {
            return null;
        }
        if ($version === null) {
            return $this->newest($key, PlanStatus::Active);
        }

        return $this->all()->first(fn (BillingPlan $p): bool => $p->key === $key && $p->version === $version && $p->status === PlanStatus::Active);
    }

    /**
     * The catalog shown to users: the newest ACTIVE or RESERVED version of each key.
     *
     * @return list<BillingPlan>
     */
    public function listed(): array
    {
        $plans = [];
        foreach ($this->all()->pluck('key')->unique() as $key) {
            $plan = $this->newest((string) $key, PlanStatus::Active, PlanStatus::Reserved);
            if ($plan !== null) {
                $plans[] = $plan;
            }
        }
        usort($plans, fn (BillingPlan $a, BillingPlan $b): int => [$a->status === PlanStatus::Reserved, $a->monthly_price_minor ?? 0, $a->key]
            <=> [$b->status === PlanStatus::Reserved, $b->monthly_price_minor ?? 0, $b->key]);

        return $plans;
    }
}
