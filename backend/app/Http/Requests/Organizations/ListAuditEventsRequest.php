<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\AuthorizesOrganizationView;
use App\Http\Requests\PaginatedRequest;

/**
 * The audit log is append-only and grows without bound, so it is paged by
 * cursor only (Phase 26): `?cursor=…&per_page=…`, never by page number,
 * which would count and skip rows of the whole log on every request.
 */
final class ListAuditEventsRequest extends PaginatedRequest
{
    use AuthorizesOrganizationView;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'per_page' => parent::rules()['per_page'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'page' => ['prohibited'],
        ];
    }

    public function cursor(): ?string
    {
        $cursor = $this->input('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
