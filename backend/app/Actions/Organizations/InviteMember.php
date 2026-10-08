<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invites an email address to an organization (docs/teams/invitations.md).
 *
 * - ADMIN or OWNER, ACTIVE organization; the role is ADMIN or MEMBER and
 *   never above the inviter's own.
 * - The address is not checked against accounts: whether it belongs to a
 *   CodeDNA user is never revealed. Only a current (ACTIVE or SUSPENDED)
 *   member of this organization is refused (ALREADY_A_MEMBER).
 * - An open invitation to the same address is revoked and replaced.
 * - Returns the raw token once; only its hash is stored, and it is never
 *   logged. Seats are taken on acceptance, not here.
 */
final readonly class InviteMember
{
    public function __construct(private OrganizationAccess $access, private OrganizationAudit $audit) {}

    /**
     * @return array{OrganizationInvitation, string} the invitation and its raw token
     */
    public function handle(Organization $organization, User $actor, string $email, OrganizationRole $role): array
    {
        $email = InvitationToken::canonicalEmail($email);
        $token = InvitationToken::generate();

        $invitation = DB::transaction(function () use ($organization, $actor, $email, $role, $token): OrganizationInvitation {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $self = $this->access->requireForChange($actor, $locked, OrganizationRole::Admin);
            if (! $this->access->mayAssign($self, $role)) {
                throw new ApiException(ErrorCode::InsufficientOrganizationRole);
            }
            $member = OrganizationMembership::query()->where('organization_id', $locked->getKey())
                ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value])
                ->whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [$email]))
                ->exists();
            if ($member) {
                throw new ApiException(ErrorCode::AlreadyAMember);
            }

            $now = Carbon::now();
            $open = OrganizationInvitation::query()->where('organization_id', $locked->getKey())->where('email', $email)
                ->whereNull('accepted_at')->whereNull('revoked_at')->lockForUpdate()->first();
            if ($open !== null) {
                $open->forceFill(['revoked_at' => $now])->save();
                $this->audit->record($locked, $actor, OrganizationAuditAction::InvitationRevoked, $open, ['email' => $email, 'reason' => 'REPLACED']);
            }

            $invitation = new OrganizationInvitation;
            $invitation->forceFill([
                'organization_id' => $locked->getKey(),
                'email' => $email,
                'role' => $role,
                'token_hash' => InvitationToken::hash($token),
                'invited_by_user_id' => $actor->getKey(),
                'expires_at' => $now->copy()->addHours(InvitationToken::TTL_HOURS),
            ])->save();
            $this->audit->record($locked, $actor, OrganizationAuditAction::MemberInvited, $invitation, ['email' => $email, 'role' => $role->value]);

            return $invitation;
        });
        Log::info('organization.member_invited', ['organization_id' => $organization->getKey(), 'invitation_id' => $invitation->id, 'actor_id' => $actor->id]);

        return [$invitation, $token];
    }
}
