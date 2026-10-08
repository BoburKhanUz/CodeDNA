<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\AuthorizesOrganizationView;
use App\Http\Requests\PaginatedRequest;

/**
 * Organization collections (members, invitations, projects, audit events):
 * ?page and ?per_page only (Phase 24). Membership is checked first.
 */
final class ListOrganizationResourcesRequest extends PaginatedRequest
{
    use AuthorizesOrganizationView;
}
