<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\InviteMember;
use App\Actions\Organizations\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\ListOrganizationResourcesRequest;
use App\Http\Requests\Organizations\StoreInvitationRequest;
use App\Http\Resources\Organizations\OrganizationInvitationResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/organizations/{organization}/invitations (docs/teams/invitations.md):
 * ADMINs and the OWNER invite, list and revoke. The raw token is returned
 * only by the creating request, once; no other response or log holds it.
 */
final class OrganizationInvitationController extends Controller
{
    public function index(ListOrganizationResourcesRequest $request, Organization $organization, Gate $gate): PaginatedCollection
    {
        $gate->authorize('administer', $organization);
        $invitations = $organization->invitations()->with('invitedBy:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($invitations, OrganizationInvitationResource::class);
    }

    public function store(StoreInvitationRequest $request, Organization $organization, InviteMember $invite): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$invitation, $token] = $invite->handle($organization, $user, $request->string('email')->toString(), $request->role());

        return new JsonResponse(['data' => [
            ...(new OrganizationInvitationResource($invitation->load('invitedBy:id,name')))->resolve($request),
            // Shown once: give it to the invitee (as /invitations/accept#<token>). Only its hash is stored.
            'token' => $token,
        ]], 201);
    }

    public function revoke(Request $request, Organization $organization, OrganizationInvitation $invitation, RevokeInvitation $revoke): OrganizationInvitationResource
    {
        /** @var User $user */
        $user = $request->user();

        return new OrganizationInvitationResource($revoke->handle($organization, $user, $invitation)->load('invitedBy:id,name'));
    }
}
