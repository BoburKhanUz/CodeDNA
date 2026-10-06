<?php

declare(strict_types=1);

namespace App\Http\Requests\Competency;

use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/competencies — ?page, ?per_page.
 */
final class ListCompetencySnapshotsRequest extends PaginatedRequest {}
