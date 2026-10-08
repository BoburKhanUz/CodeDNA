<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Concerns\CursorPaginated;
use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/billing/usage: ?page and ?per_page only (Phase 23).
 */
final class ListBillingUsageRequest extends PaginatedRequest
{
    use CursorPaginated;
}
