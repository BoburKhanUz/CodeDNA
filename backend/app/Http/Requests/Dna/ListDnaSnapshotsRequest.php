<?php

declare(strict_types=1);

namespace App\Http\Requests\Dna;

use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/dna — ?page, ?per_page.
 */
final class ListDnaSnapshotsRequest extends PaginatedRequest {}
