<?php

declare(strict_types=1);

namespace App\Http\Requests\Roadmap;

use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/roadmaps — ?page, ?per_page.
 */
final class ListRoadmapsRequest extends PaginatedRequest {}
