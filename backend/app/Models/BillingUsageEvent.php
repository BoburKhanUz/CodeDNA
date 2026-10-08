<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\QuotaKey;
use App\Enums\Billing\UsageOutcome;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry of the usage ledger (Phase 23): what was consumed, refunded or
 * refused, by whom, under which plan version and subscription, for which
 * resource, in which period. Append-only. Its billing subject is a user or, for
 * team projects (Phase 24), an organization. It never holds source code, URLs
 * or secrets: only the resource's type and ID.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string|null $organization_id
 * @property string|null $billing_subscription_id
 * @property string $billing_plan_id
 * @property QuotaKey $quota_key
 * @property UsageOutcome $outcome
 * @property int $amount
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property Carbon $created_at
 */
class BillingUsageEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $event) => throw DomainRuleViolation::immutable($event, 'updated'));
        static::deleting(static fn (self $event) => throw DomainRuleViolation::immutable($event, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quota_key' => QuotaKey::class,
            'outcome' => UsageOutcome::class,
            'amount' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }
}
