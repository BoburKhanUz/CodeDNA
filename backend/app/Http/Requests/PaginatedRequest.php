<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesProjectView;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Page-based pagination for collection endpoints: ?page=2&per_page=25.
 * On project routes the project is authorized before validation.
 */
abstract class PaginatedRequest extends FormRequest
{
    use AuthorizesProjectView;

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }
}
