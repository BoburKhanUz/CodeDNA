<?php

declare(strict_types=1);

namespace App\Services\Billing\Catalog;

/**
 * Team entitlements, version 1.0.0 (docs/teams/billing-boundary.md#seats).
 * Frozen like the plan catalog: a new organization's billing account is
 * created from these values and records this version. Organizations are
 * measured against the FREE plan (by key) until team billing exists; the
 * reserved TEAM_READY plan stays reserved.
 */
final class TeamEntitlementsV1
{
    public const VERSION = '1.0.0';

    /** ACTIVE memberships (the owner included) a new organization may have. */
    public const SEATS = 5;
}
