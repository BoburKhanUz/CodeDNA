<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Organizations\TeamAnalytics;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/organizations/{organization}/analytics: deterministic,
 * read-only figures over the organization's projects (any member). See
 * docs/teams/team-analytics.md for what is and is not aggregated.
 */
final class OrganizationAnalyticsController extends Controller
{
    public function __invoke(Organization $organization, Gate $gate, TeamAnalytics $analytics): JsonResponse
    {
        $gate->authorize('view', $organization);

        return new JsonResponse(['data' => $analytics->for($organization)]);
    }
}
