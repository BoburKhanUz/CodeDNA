<?php

declare(strict_types=1);

namespace App\Http\Pagination;

enum CursorDirection: string
{
    /** Further along the list's order (older rows). */
    case Next = 'next';

    /** Back towards the start of the list (newer rows). */
    case Previous = 'prev';
}
