<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Pagination\CursorPage;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A keyset page: `{"data": [...], "meta": {"per_page", "next_cursor",
 * "prev_cursor"}}` (docs/api/README.md#pagination). No total: counting a
 * long list is exactly what keyset pagination avoids. A null cursor means
 * there is nothing further in that direction.
 */
final readonly class CursorCollection implements Responsable
{
    /**
     * @param  CursorPage<mixed>  $page
     * @param  class-string<JsonResource>  $resource
     */
    public function __construct(private CursorPage $page, private string $resource) {}

    public function toResponse($request): JsonResponse
    {
        /** @var Request $request */
        return new JsonResponse([
            'data' => array_map(fn (mixed $item): array => (new $this->resource($item))->resolve($request), $this->page->items),
            'meta' => [
                'per_page' => $this->page->perPage,
                'next_cursor' => $this->page->nextCursor,
                'prev_cursor' => $this->page->previousCursor,
            ],
        ]);
    }
}
