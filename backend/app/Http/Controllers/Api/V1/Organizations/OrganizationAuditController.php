<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\ListOrganizationResourcesRequest;
use App\Http\Resources\Organizations\OrganizationAuditEventResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * GET /api/v1/organizations/{organization}/audit-events: the audit log,
 * newest first (ADMIN or OWNER). Read-only: no route changes it.
 */
final class OrganizationAuditController extends Controller
{
    public function __invoke(ListOrganizationResourcesRequest $request, Organization $organization, Gate $gate): PaginatedCollection
    {
        $gate->authorize('administer', $organization);
        $events = OrganizationAuditEvent::query()->where('organization_id', $organization->getKey())->with('actor:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($events, OrganizationAuditEventResource::class);
    }
}
