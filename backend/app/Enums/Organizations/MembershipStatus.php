<?php

declare(strict_types=1);

namespace App\Enums\Organizations;

/**
 * Only ACTIVE memberships grant access and use a seat. A REMOVED membership
 * is kept for the record; the user can come back only through a new invitation.
 */
enum MembershipStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Removed = 'REMOVED';
}
