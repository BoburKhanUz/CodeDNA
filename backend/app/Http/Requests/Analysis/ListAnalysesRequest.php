<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use App\Http\Requests\Concerns\CursorPaginated;
use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/analyses — ?page, ?per_page.
 */
final class ListAnalysesRequest extends PaginatedRequest
{
    use CursorPaginated;
}
