<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\QuotaKey;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan version's limit for one quota; null is unlimited (Phase 23). Immutable.
 *
 * @property string $billing_plan_id
 * @property QuotaKey $quota_key
 * @property int|null $limit
 */
class BillingPlanQuota extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'billing_plan_id';

    protected static function booted(): void
    {
        static::updating(static fn (self $row) => throw DomainRuleViolation::immutable($row, 'updated'));
        static::deleting(static fn (self $row) => throw DomainRuleViolation::immutable($row, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quota_key' => QuotaKey::class, 'limit' => 'integer'];
    }
}
