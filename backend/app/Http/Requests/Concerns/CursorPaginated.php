<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Opt-in keyset pagination for long, append-only lists (Phase 26,
 * docs/api/README.md#pagination): `?cursor=` (empty for the first page)
 * switches the list to cursor mode, which never counts and never skips rows
 * with OFFSET. Page mode stays available where a client needs totals.
 * A cursor is opaque and signed (App\Http\Pagination\CursorCodec).
 */
trait CursorPaginated
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512', 'prohibits:page'],
        ];
    }

    public function usesCursor(): bool
    {
        return $this->has('cursor');
    }

    public function cursor(): ?string
    {
        $cursor = $this->input('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
