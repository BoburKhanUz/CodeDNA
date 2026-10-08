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
use App\Services\Billing\QuotaService;
use App\Services\Organizations\OrganizationAccess;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Changes a member's role and/or status (ACTIVE <-> SUSPENDED)
 * (docs/teams/authorization.md#membership-changes).
 *
 * - The actor must be ADMIN or OWNER and outrank the target (never
 *   oneself); a role can be given only up to the actor's own, never OWNER.
 * - The owner's membership is never demoted or suspended.
 * - Reactivating takes a seat: checked under the organization row lock.
 * - A REMOVED membership is gone for this API (404); the person comes back
 *   only through a new invitation.
 */
final readonly class ChangeMembership
{
    public function __construct(private OrganizationAccess $access, private OrganizationAudit $audit, private QuotaService $quotas) {}

    public function handle(Organization $organization, User $actor, OrganizationMembership $membership, ?OrganizationRole $role, ?MembershipStatus $status): OrganizationMembership
    {
        return DB::transaction(function () use ($organization, $actor, $membership, $role, $status): OrganizationMembership {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $self = $this->access->requireForChange($actor, $locked, OrganizationRole::Admin);
            $target = OrganizationMembership::query()->whereKey($membership->getKey())->where('organization_id', $locked->getKey())
                ->lockForUpdate()->first();
            if ($target === null || $target->status === MembershipStatus::Removed) {
                throw new ApiException(ErrorCode::ResourceNotFound);
            }
            if ($target->role === OrganizationRole::Owner) {
                throw new ApiException(ErrorCode::CannotChangeOwnerRole);
            }
            if (! $this->access->outranks($self, $target) || ($role !== null && ! $this->access->mayAssign($self, $role))) {
                throw new ApiException(ErrorCode::InsufficientOrganizationRole);
            }

            if ($status !== null && $status !== $target->status) {
                if ($status === MembershipStatus::Active) {
                    $this->quotas->requireSeat($locked);
                }
                $target->forceFill(['status' => $status])->save();
                $this->audit->record($locked, $actor, $status === MembershipStatus::Active
                    ? OrganizationAuditAction::MemberReactivated : OrganizationAuditAction::MemberSuspended, $target, ['user_id' => $target->user_id]);
            }
            if ($role !== null && $role !== $target->role) {
                $previous = $target->role;
                $target->forceFill(['role' => $role])->save();
                $this->audit->record($locked, $actor, OrganizationAuditAction::MemberRoleChanged, $target,
                    ['user_id' => $target->user_id, 'role' => ['from' => $previous->value, 'to' => $role->value]]);
            }
            Log::info('organization.membership_changed', ['organization_id' => $locked->id, 'membership_id' => $target->id, 'actor_id' => $actor->id]);

            return $target;
        });
    }
}
