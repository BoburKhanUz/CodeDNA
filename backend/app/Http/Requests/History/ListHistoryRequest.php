<?php

declare(strict_types=1);

namespace App\Http\Requests\History;

use App\Http\Requests\GitHub\OnlyFields;
use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/history — ?page, ?per_page (default 25,
 * at most 100), newest assessment first. Nothing else is accepted.
 */
final class ListHistoryRequest extends PaginatedRequest
{
    use OnlyFields;

    protected function allowedFields(): array
    {
        return ['page', 'per_page'];
    }
}
