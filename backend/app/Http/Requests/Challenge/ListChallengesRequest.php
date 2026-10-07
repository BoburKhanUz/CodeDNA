<?php

declare(strict_types=1);

namespace App\Http\Requests\Challenge;

use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/challenges and .../submissions — ?page, ?per_page.
 */
final class ListChallengesRequest extends PaginatedRequest {}
