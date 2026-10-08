<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's place in an organization (Phase 24). One row per organization
 * and user; removal is a status, never a delete, so the record stays.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property OrganizationRole $role
 * @property MembershipStatus $status
 * @property Carbon $joined_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrganizationMembership extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        static::deleting(static fn (self $membership) => throw DomainRuleViolation::immutable($membership, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
