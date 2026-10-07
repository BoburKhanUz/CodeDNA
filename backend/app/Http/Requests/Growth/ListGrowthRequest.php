<?php

declare(strict_types=1);

namespace App\Http\Requests\Growth;

use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/growth/timeline — ?page, ?per_page.
 */
final class ListGrowthRequest extends PaginatedRequest {}
