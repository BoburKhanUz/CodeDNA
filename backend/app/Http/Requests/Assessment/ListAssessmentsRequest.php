<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/assessments — ?page, ?per_page.
 */
final class ListAssessmentsRequest extends PaginatedRequest {}
