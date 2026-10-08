<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Revokes an open invitation (ADMIN or OWNER, ACTIVE organization). An
 * accepted invitation cannot be revoked (remove the member instead); a
 * revoked one stays revoked. Expired invitations can be revoked, which
 * closes them for good.
 */
final readonly class RevokeInvitation
{
    public function __construct(private OrganizationAccess $access, private OrganizationAudit $audit) {}

    public function handle(Organization $organization, User $actor, OrganizationInvitation $invitation): OrganizationInvitation
    {
        return DB::transaction(function () use ($organization, $actor, $invitation): OrganizationInvitation {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $this->access->requireForChange($actor, $locked, OrganizationRole::Admin);
            $target = OrganizationInvitation::query()->whereKey($invitation->getKey())->where('organization_id', $locked->getKey())
                ->lockForUpdate()->first() ?? throw new ApiException(ErrorCode::ResourceNotFound);
            if ($target->accepted_at !== null) {
                throw new ApiException(ErrorCode::InvitationAlreadyAccepted);
            }
            if ($target->revoked_at !== null) {
                throw new ApiException(ErrorCode::InvitationRevoked);
            }
            $target->forceFill(['revoked_at' => Carbon::now()])->save();
            $this->audit->record($locked, $actor, OrganizationAuditAction::InvitationRevoked, $target, ['email' => $target->email]);

            return $target;
        });
    }
}
