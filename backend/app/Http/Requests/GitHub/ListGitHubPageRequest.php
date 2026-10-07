<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use App\Http\Requests\PaginatedRequest;

/**
 * A page of GitHub repositories or branches: at most 100 per page, and at
 * most 50 pages (5,000 items), so browsing stays bounded.
 */
final class ListGitHubPageRequest extends PaginatedRequest
{
    public const MAX_PAGE = 50;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 50);
    }
}
