<?php

declare(strict_types=1);

namespace App\Http\Requests\SkillGap;

use App\Http\Requests\PaginatedRequest;

/**
 * GET /api/v1/projects/{project}/skill-gaps — ?page, ?per_page.
 */
final class ListSkillGapSnapshotsRequest extends PaginatedRequest {}
