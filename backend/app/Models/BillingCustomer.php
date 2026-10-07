<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A user's identity at one payment provider (Phase 23). Created only by the
 * billing domain, never from client input; never changed or deleted.
 *
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_customer_ref
 * @property Carbon $created_at
 */
class BillingCustomer extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $customer) => throw DomainRuleViolation::immutable($customer, 'updated'));
        static::deleting(static fn (self $customer) => throw DomainRuleViolation::immutable($customer, 'deleted'));
    }
}
