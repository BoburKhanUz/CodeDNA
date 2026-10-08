<?php

declare(strict_types=1);

namespace App\Enums\Organizations;

/**
 * Lifecycle of an organization (docs/teams/teams-architecture.md#organization-status).
 * Only ACTIVE organizations accept changes; SUSPENDED and ARCHIVED keep
 * their history readable to their members.
 */
enum OrganizationStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Archived = 'ARCHIVED';
}
