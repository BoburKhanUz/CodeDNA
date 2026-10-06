<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use App\Http\Requests\PaginatedRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/projects — ?page, ?per_page, optional ?status=ACTIVE|ARCHIVED.
 */
final class ListProjectsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['sometimes', 'string', Rule::enum(ProjectStatus::class)],
        ];
    }
}
