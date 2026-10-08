<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes a member: the membership becomes REMOVED (kept for the record,
 * never deleted) and access ends with this transaction. The owner cannot
 * be removed; otherwise the same rules as ChangeMembership.
 */
final readonly class RemoveMember
{
    public function __construct(private OrganizationAccess $access, private OrganizationAudit $audit) {}

    public function handle(Organization $organization, User $actor, OrganizationMembership $membership): OrganizationMembership
    {
        return DB::transaction(function () use ($organization, $actor, $membership): OrganizationMembership {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $self = $this->access->requireForChange($actor, $locked, OrganizationRole::Admin);
            $target = OrganizationMembership::query()->whereKey($membership->getKey())->where('organization_id', $locked->getKey())
                ->lockForUpdate()->first();
            if ($target === null || $target->status === MembershipStatus::Removed) {
                throw new ApiException(ErrorCode::ResourceNotFound);
            }
            if ($target->role === OrganizationRole::Owner) {
                throw new ApiException(ErrorCode::CannotRemoveOwner);
            }
            if (! $this->access->outranks($self, $target)) {
                throw new ApiException(ErrorCode::InsufficientOrganizationRole);
            }
            $target->forceFill(['status' => MembershipStatus::Removed])->save();
            $this->audit->record($locked, $actor, OrganizationAuditAction::MemberRemoved, $target, ['user_id' => $target->user_id]);
            Log::info('organization.member_removed', ['organization_id' => $locked->id, 'membership_id' => $target->id, 'actor_id' => $actor->id]);

            return $target;
        });
    }
}
