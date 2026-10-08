<?php

declare(strict_types=1);

namespace App\Enums\Organizations;

/**
 * Server-owned audit actions (docs/teams/teams-architecture.md#audit-log).
 */
enum OrganizationAuditAction: string
{
    case OrganizationCreated = 'ORGANIZATION_CREATED';
    case OrganizationUpdated = 'ORGANIZATION_UPDATED';
    case OrganizationArchived = 'ORGANIZATION_ARCHIVED';
    case MemberInvited = 'MEMBER_INVITED';
    case MemberJoined = 'MEMBER_JOINED';
    case MemberRoleChanged = 'MEMBER_ROLE_CHANGED';
    case MemberSuspended = 'MEMBER_SUSPENDED';
    case MemberReactivated = 'MEMBER_REACTIVATED';
    case MemberRemoved = 'MEMBER_REMOVED';
    case InvitationRevoked = 'INVITATION_REVOKED';
    case ProjectCreated = 'PROJECT_CREATED';
    case ProjectArchived = 'PROJECT_ARCHIVED';
}
