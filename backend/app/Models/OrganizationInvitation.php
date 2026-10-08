<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Organizations\InvitationStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to join an organization (docs/teams/invitations.md). Only
 * the SHA-256 of its token is stored; the token itself is shown once, to
 * the inviter. Accepted or revoked invitations never change again.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property OrganizationRole $role
 * @property string $token_hash
 * @property string $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by_user_id
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrganizationInvitation extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    protected static function booted(): void
    {
        static::deleting(static fn (self $invitation) => throw DomainRuleViolation::immutable($invitation, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function statusAt(Carbon $now): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            ! $now->lessThan($this->expires_at) => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
