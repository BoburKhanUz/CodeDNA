<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The organization as a billing subject (docs/teams/billing-boundary.md):
 * the plan version its team projects are measured against and its seat
 * entitlement. Created with the organization from the team entitlements;
 * there is no API that changes it (no team payments yet).
 *
 * @property string $organization_id
 * @property string $billing_plan_id
 * @property string $entitlement_version
 * @property int $seat_limit
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrganizationBillingAccount extends Model
{
    protected $primaryKey = 'organization_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::deleting(static fn (self $account) => throw DomainRuleViolation::immutable($account, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['seat_limit' => 'integer'];
    }

    /**
     * @return BelongsTo<BillingPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }
}
