<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\Organizations\InvitationStatus;
use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationAuditAction;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Billing\QuotaService;
use App\Services\Organizations\OrganizationAccess;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Accepts an invitation for the signed-in user (docs/teams/invitations.md#acceptance).
 *
 * In one transaction, with the invitation and organization rows locked:
 * the token must name a stored invitation (404 otherwise, the same for a
 * malformed token); the invitation must be addressed to the user's email
 * (canonical form); it must still be open (not accepted, revoked or
 * expired); the organization must be ACTIVE; a seat must be free. Only
 * then does the membership become ACTIVE, the invitation accepted (it can
 * never be used again) and MEMBER_JOINED recorded. Concurrent acceptances
 * are decided one at a time by the organization row lock.
 */
final readonly class AcceptInvitation
{
    public function __construct(private OrganizationAccess $access, private OrganizationAudit $audit, private QuotaService $quotas) {}

    public function handle(User $user, string $token): OrganizationMembership
    {
        if (! InvitationToken::wellFormed($token)) {
            throw new ApiException(ErrorCode::ResourceNotFound);
        }
        $hash = InvitationToken::hash($token);

        $membership = DB::transaction(function () use ($user, $hash): OrganizationMembership {
            $found = OrganizationInvitation::query()->where('token_hash', $hash)->first()
                ?? throw new ApiException(ErrorCode::ResourceNotFound);
            // Lock order everywhere: organization, then its rows.
            $organization = Organization::query()->whereKey($found->organization_id)->lockForUpdate()->firstOrFail();
            $invitation = OrganizationInvitation::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($invitation->email, InvitationToken::canonicalEmail((string) $user->email))) {
                Log::warning('organization.invitation_email_mismatch', ['invitation_id' => $invitation->id, 'user_id' => $user->id]);

                throw new ApiException(ErrorCode::InvitationEmailMismatch);
            }
            match ($invitation->statusAt(Carbon::now())) {
                InvitationStatus::Accepted => throw new ApiException(ErrorCode::InvitationAlreadyAccepted),
                InvitationStatus::Revoked => throw new ApiException(ErrorCode::InvitationRevoked),
                InvitationStatus::Expired => throw new ApiException(ErrorCode::InvitationExpired),
                InvitationStatus::Pending => null,
            };
            $this->access->requireActive($organization);

            $membership = OrganizationMembership::query()->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())->lockForUpdate()->first();
            if ($membership?->status === MembershipStatus::Active) {
                throw new ApiException(ErrorCode::AlreadyAMember);
            }
            if ($membership?->status === MembershipStatus::Suspended) {
                // An invitation never lifts a suspension.
                throw new ApiException(ErrorCode::MembershipSuspended);
            }
            $this->quotas->requireSeat($organization);

            $now = Carbon::now();
            $membership ??= new OrganizationMembership;
            $membership->forceFill([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'role' => $invitation->role,
                'status' => MembershipStatus::Active,
                'joined_at' => $now,
            ])->save();
            $invitation->forceFill(['accepted_at' => $now, 'accepted_by_user_id' => $user->getKey()])->save();
            $this->audit->record($organization, $user, OrganizationAuditAction::MemberJoined, $membership,
                ['user_id' => (string) $user->getKey(), 'role' => $invitation->role->value, 'invitation_id' => $invitation->id]);

            return $membership;
        });
        Log::info('organization.member_joined', ['organization_id' => $membership->organization_id, 'membership_id' => $membership->id, 'user_id' => $user->id]);

        return $membership;
    }
}
