<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Organizations\OrganizationStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An organization (shown to users as a "team"), Phase 24
 * (docs/teams/teams-architecture.md). Created only by CreateOrganization,
 * together with its OWNER membership and billing account. The owner and
 * slug never change; organizations are archived, never deleted.
 *
 * @property string $id
 * @property string $owner_user_id
 * @property string $name
 * @property string $slug
 * @property OrganizationStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Organization extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        static::deleting(static fn (self $organization) => throw DomainRuleViolation::immutable($organization, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => OrganizationStatus::class];
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasOne<OrganizationBillingAccount, $this>
     */
    public function billingAccount(): HasOne
    {
        return $this->hasOne(OrganizationBillingAccount::class);
    }
}
