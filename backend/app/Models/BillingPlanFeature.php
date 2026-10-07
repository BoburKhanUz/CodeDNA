<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\Feature;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;

/**
 * A feature a plan version includes (Phase 23). Immutable.
 *
 * @property string $billing_plan_id
 * @property Feature $feature
 */
class BillingPlanFeature extends Model
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
        return ['feature' => Feature::class];
    }
}
