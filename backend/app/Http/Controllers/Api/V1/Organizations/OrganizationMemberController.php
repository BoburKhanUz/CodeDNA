<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\ChangeMembership;
use App\Actions\Organizations\RemoveMember;
use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\ListOrganizationResourcesRequest;
use App\Http\Requests\Organizations\UpdateMembershipRequest;
use App\Http\Resources\Organizations\OrganizationMembershipResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/organizations/{organization}/members. Any member may list
 * members (emails only for ADMINs and the OWNER); changes are made by
 * ChangeMembership and RemoveMember. DELETE marks the membership REMOVED,
 * it never deletes it.
 */
final class OrganizationMemberController extends Controller
{
    public function index(ListOrganizationResourcesRequest $request, Organization $organization, OrganizationAccess $access): PaginatedCollection
    {
        /** @var User $user */
        $user = $request->user();
        $withEmail = $access->require($user, $organization)->role->atLeast(OrganizationRole::Admin);
        $members = $organization->memberships()->with('user:id,name,email')
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value])
            ->orderByRaw("CASE role WHEN 'OWNER' THEN 0 WHEN 'ADMIN' THEN 1 ELSE 2 END")
            ->orderBy('joined_at')->orderBy('id')
            ->paginate($request->perPage(), page: $request->page());
        $members->setCollection($members->getCollection()->map(fn (OrganizationMembership $m) => new OrganizationMembershipResource($m, $withEmail)));

        return new PaginatedCollection($members, OrganizationMembershipResource::class);
    }

    public function update(UpdateMembershipRequest $request, Organization $organization, OrganizationMembership $membership, ChangeMembership $change): OrganizationMembershipResource
    {
        /** @var User $user */
        $user = $request->user();
        $changed = $change->handle($organization, $user, $membership, $request->role(), $request->status());

        return new OrganizationMembershipResource($changed->load('user:id,name,email'), true);
    }

    public function destroy(Request $request, Organization $organization, OrganizationMembership $membership, RemoveMember $remove): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $removed = $remove->handle($organization, $user, $membership);

        return (new OrganizationMembershipResource($removed->load('user:id,name,email'), true))->response();
    }
}
