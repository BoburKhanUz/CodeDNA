<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use App\Http\Requests\Concerns\CursorPaginated;
use App\Http\Requests\PaginatedRequest;

/** GET /api/v1/projects/{project}/github/imports: newest first. */
final class ListGitHubImportsRequest extends PaginatedRequest
{
    use CursorPaginated;
}
