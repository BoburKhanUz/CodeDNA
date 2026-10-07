<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * ACCEPTED: a unit was consumed for a resource. REFUNDED: that consumption
 * was returned because the operation failed without a result. REJECTED: a
 * request was refused because the quota was exhausted (nothing consumed).
 */
enum UsageOutcome: string
{
    case Accepted = 'ACCEPTED';
    case Refunded = 'REFUNDED';
    case Rejected = 'REJECTED';
}
