<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * ACTIVE: offered and used as the current version of its plan key.
 * RESERVED: defined for a later phase (TEAM_READY); never offered, never the
 * fallback, not purchasable. RETIRED: kept for existing subscriptions only.
 */
enum PlanStatus: string
{
    case Active = 'ACTIVE';
    case Reserved = 'RESERVED';
    case Retired = 'RETIRED';
}
