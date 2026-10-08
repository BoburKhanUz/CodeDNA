<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Pagination\KeysetPaginator;
use App\Http\Requests\Organizations\ListAuditEventsRequest;
use App\Http\Resources\CursorCollection;
use App\Http\Resources\Organizations\OrganizationAuditEventResource;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * GET /api/v1/organizations/{organization}/audit-events: the audit log,
 * newest first (ADMIN or OWNER). Read-only: no route changes it.
 *
 * Keyset pages only (Phase 26): each page is one range read of the
 * (organization_id, created_at, id) index, whatever the log's length.
 */
final class OrganizationAuditController extends Controller
{
    public function __invoke(ListAuditEventsRequest $request, Organization $organization, Gate $gate, KeysetPaginator $keyset): CursorCollection
    {
        $gate->authorize('administer', $organization);

        return new CursorCollection($keyset->paginate(
            OrganizationAuditEvent::query()->where('organization_id', $organization->getKey())->with('actor:id,name'),
            ['organization_audit_events.created_at', 'organization_audit_events.id'],
            fn (OrganizationAuditEvent $event): array => [(string) $event->getRawOriginal('created_at'), $event->id],
            'audit:'.$organization->getKey(), $request->cursor(), $request->perPage(),
        ), OrganizationAuditEventResource::class);
    }
}
