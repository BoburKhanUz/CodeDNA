<?php

declare(strict_types=1);

namespace App\Enums\Organizations;

/**
 * Derived, never stored: an invitation is PENDING until it is accepted,
 * revoked or past its expiry.
 */
enum InvitationStatus: string
{
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';
}
