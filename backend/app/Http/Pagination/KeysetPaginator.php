<?php

declare(strict_types=1);

namespace App\Http\Pagination;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Keyset ("cursor") pagination over a list ordered newest first by a unique
 * key (Phase 26, docs/api/README.md#pagination).
 *
 * Each page is one indexed range read of perPage + 1 rows: no COUNT and no
 * OFFSET, so its cost does not depend on how deep the page is or how long
 * the list is. Rows inserted while a client pages are never skipped or
 * repeated (the key, not a position, marks the boundary). The key columns
 * are fixed by the caller, never taken from the request.
 */
final readonly class KeysetPaginator
{
    public function __construct(private CursorCodec $codec) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  scoped to the list, without ordering
     * @param  list<string>  $columns  the unique sort key, qualified, newest first (DESC)
     * @param  Closure(TModel): list<string>  $key  the key values of a row, as stored
     * @param  string  $list  identifies the list and its scope (cursors are bound to it)
     * @param  int  $indexPrefix  when the key extends past the index, how many leading
     *                            columns are indexed: they are also given as a range so
     *                            the index bounds the read (the full key decides)
     * @return CursorPage<TModel>
     */
    public function paginate(Builder $query, array $columns, Closure $key, string $list, ?string $cursor, int $perPage, int $indexPrefix = 0): CursorPage
    {
        $direction = CursorDirection::Next;
        $tuple = '('.implode(', ', $columns).')';
        $marks = static fn (int $n): string => '('.implode(', ', array_fill(0, $n, '?')).')';
        if ($cursor !== null) {
            [$values, $direction] = $this->codec->decode($list, $cursor, count($columns));
            $before = $direction === CursorDirection::Next;
            if ($indexPrefix > 0) {
                $prefix = '('.implode(', ', array_slice($columns, 0, $indexPrefix)).')';
                $query->whereRaw($prefix.($before ? ' <= ' : ' >= ').$marks($indexPrefix), array_slice($values, 0, $indexPrefix));
            }
            $query->whereRaw($tuple.($before ? ' < ' : ' > ').$marks(count($columns)), $values);
        }
        foreach ($columns as $column) {
            $query->orderBy($column, $direction === CursorDirection::Next ? 'desc' : 'asc');
        }

        $rows = $query->limit($perPage + 1)->get()->all();
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        if ($direction === CursorDirection::Previous) {
            $rows = array_reverse($rows);
        }

        $first = $rows[0] ?? null;
        $last = $rows === [] ? null : $rows[count($rows) - 1];
        // Older rows exist after a full forward page, or always when moving back.
        $hasNext = $direction === CursorDirection::Next ? $more : $cursor !== null;
        // Newer rows exist when we came forward from a cursor, or a full backward page.
        $hasPrevious = $direction === CursorDirection::Next ? $cursor !== null : $more;

        return new CursorPage(
            $rows,
            $perPage,
            $hasNext && $last !== null ? $this->codec->encode($list, $key($last), CursorDirection::Next) : null,
            $hasPrevious && $first !== null ? $this->codec->encode($list, $key($first), CursorDirection::Previous) : null,
        );
    }
}
