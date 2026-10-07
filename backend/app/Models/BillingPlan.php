<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\Feature;
use App\Enums\Billing\PlanStatus;
use App\Enums\Billing\QuotaKey;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of a server-owned plan (Phase 23). Created only by migrations
 * from a frozen catalog definition; never updated or deleted (model and
 * trigger). Subscriptions and usage point at the exact version they used.
 *
 * @property string $id
 * @property string $key
 * @property string $version
 * @property string $catalog_version
 * @property string $name
 * @property string $description
 * @property PlanStatus $status
 * @property string $currency
 * @property int|null $monthly_price_minor
 * @property int|null $annual_price_minor
 * @property string $fingerprint
 * @property Carbon $created_at
 */
class BillingPlan extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $plan) => throw DomainRuleViolation::immutable($plan, 'updated'));
        static::deleting(static fn (self $plan) => throw DomainRuleViolation::immutable($plan, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'monthly_price_minor' => 'integer',
            'annual_price_minor' => 'integer',
        ];
    }

    /**
     * @return HasMany<BillingPlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(BillingPlanFeature::class);
    }

    /**
     * @return HasMany<BillingPlanQuota, $this>
     */
    public function quotas(): HasMany
    {
        return $this->hasMany(BillingPlanQuota::class);
    }

    public function includes(Feature $feature): bool
    {
        return $this->features->contains(fn (BillingPlanFeature $f): bool => $f->feature === $feature);
    }

    /** The plan's limit for a quota: null when unlimited. A quota the plan does not define is 0. */
    public function limitFor(QuotaKey $key): ?int
    {
        $quota = $this->quotas->first(fn (BillingPlanQuota $q): bool => $q->quota_key === $key);

        return $quota === null ? 0 : $quota->limit;
    }
}
