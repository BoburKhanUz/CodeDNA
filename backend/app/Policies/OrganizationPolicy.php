<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use Illuminate\Auth\Access\Response;

/**
 * Organization abilities (docs/teams/authorization.md), all decided by
 * OrganizationAccess: a non-member gets 404, a suspended member 403
 * MEMBERSHIP_SUSPENDED, too low a role 403 INSUFFICIENT_ORGANIZATION_ROLE.
 * Reads stay available while the organization is suspended or archived.
 *
 * Changes are authorized again by their actions, under the organization
 * row lock, together with the organization's state and the target's rank.
 */
final readonly class OrganizationPolicy
{
    public function __construct(private OrganizationAccess $access) {}

    /** The organization, its members, projects and analytics: any ACTIVE member. */
    public function view(User $actor, Organization $organization): Response
    {
        $this->access->require($actor, $organization);

        return Response::allow();
    }

    /** Invitations, the audit log and billing details: ADMIN or OWNER. */
    public function administer(User $actor, Organization $organization): Response
    {
        $this->access->require($actor, $organization, OrganizationRole::Admin);

        return Response::allow();
    }
}
