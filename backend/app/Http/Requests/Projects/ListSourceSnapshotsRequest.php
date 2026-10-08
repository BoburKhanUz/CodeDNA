<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\CursorPaginated;
use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/source-snapshots — ?page, ?per_page.
 */
final class ListSourceSnapshotsRequest extends PaginatedRequest
{
    use CursorPaginated;
}
