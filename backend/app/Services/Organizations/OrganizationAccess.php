<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * The one place organization access is decided (docs/teams/authorization.md).
 * Every organization-scoped operation asks, in this order:
 *
 * 1. Is the caller a member at all? No membership, or a REMOVED one: 404
 *    RESOURCE_NOT_FOUND, the same as an organization that does not exist,
 *    so organizations cannot be discovered.
 * 2. Is the membership ACTIVE? SUSPENDED: 403 MEMBERSHIP_SUSPENDED.
 * 3. Does the role allow it? 403 INSUFFICIENT_ORGANIZATION_ROLE.
 * 4. For changes, is the organization ACTIVE? 409 ORGANIZATION_SUSPENDED or
 *    ORGANIZATION_ARCHIVED; reads stay available.
 *
 * Only the server's membership row counts: never a role, an organization ID
 * or a membership ID sent by the client.
 */
final readonly class OrganizationAccess
{
    /** The caller's membership, of any status, or null. */
    public function membership(User|string $user, Organization|string $organization): ?OrganizationMembership
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization)
            ->where('user_id', $user instanceof User ? $user->getKey() : $user)
            ->first();
    }

    /**
     * The caller's ACTIVE membership with at least the given role.
     *
     * @throws ApiException RESOURCE_NOT_FOUND, MEMBERSHIP_SUSPENDED, INSUFFICIENT_ORGANIZATION_ROLE
     */
    public function require(User|string $user, Organization|string $organization, OrganizationRole $atLeast = OrganizationRole::Member): OrganizationMembership
    {
        $membership = $this->membership($user, $organization);
        if ($membership === null || $membership->status === MembershipStatus::Removed) {
            throw new ApiException(ErrorCode::ResourceNotFound);
        }
        if ($membership->status !== MembershipStatus::Active) {
            throw new ApiException(ErrorCode::MembershipSuspended);
        }
        if (! $membership->role->atLeast($atLeast)) {
            throw new ApiException(ErrorCode::InsufficientOrganizationRole);
        }

        return $membership;
    }

    /**
     * The caller may change something in the organization: an ACTIVE
     * membership with the role, in an ACTIVE organization.
     *
     * @throws ApiException as require(), and ORGANIZATION_SUSPENDED, ORGANIZATION_ARCHIVED
     */
    public function requireForChange(User|string $user, Organization $organization, OrganizationRole $atLeast = OrganizationRole::Member): OrganizationMembership
    {
        $membership = $this->require($user, $organization, $atLeast);
        $this->requireActive($organization);

        return $membership;
    }

    /** @throws ApiException ORGANIZATION_SUSPENDED, ORGANIZATION_ARCHIVED */
    public function requireActive(Organization $organization): void
    {
        match ($organization->status) {
            OrganizationStatus::Active => null,
            OrganizationStatus::Suspended => throw new ApiException(ErrorCode::OrganizationSuspended),
            OrganizationStatus::Archived => throw new ApiException(ErrorCode::OrganizationArchived),
        };
    }

    /**
     * Whether an actor may act on another member (change role or status,
     * remove): only on a member of a strictly lower role, never on oneself.
     * The owner is protected separately (CANNOT_REMOVE_OWNER,
     * CANNOT_CHANGE_OWNER_ROLE).
     */
    public function outranks(OrganizationMembership $actor, OrganizationMembership $target): bool
    {
        return $actor->id !== $target->id && $actor->role->rank() > $target->role->rank();
    }

    /** Whether an actor may give a role (by invitation or role change): never OWNER, never above their own. */
    public function mayAssign(OrganizationMembership $actor, OrganizationRole $role): bool
    {
        return $role !== OrganizationRole::Owner && $actor->role->atLeast($role);
    }
}
