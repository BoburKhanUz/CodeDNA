<?php

declare(strict_types=1);

namespace App\Http\Pagination;

/**
 * One keyset page: the items in list order and the cursors around them.
 *
 * @template TItem
 */
final readonly class CursorPage
{
    /**
     * @param  list<TItem>  $items
     */
    public function __construct(
        public array $items,
        public int $perPage,
        public ?string $nextCursor,
        public ?string $previousCursor,
    ) {}
}
