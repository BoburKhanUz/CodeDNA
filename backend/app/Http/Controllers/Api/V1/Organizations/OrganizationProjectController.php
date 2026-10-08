<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Projects\CreateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\ListOrganizationResourcesRequest;
use App\Http\Requests\Organizations\StoreOrganizationProjectRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\ProjectResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/organizations/{organization}/projects: the organization's
 * projects (any member) and creating one (ADMIN or OWNER, through the same
 * CreateProject action as personal projects). Each project is then used
 * through the ordinary /api/v1/projects/{project} routes, where
 * ProjectPolicy checks the membership.
 */
final class OrganizationProjectController extends Controller
{
    public function index(ListOrganizationResourcesRequest $request, Organization $organization, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $organization);
        $projects = $organization->projects()
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($projects, ProjectResource::class);
    }

    public function store(StoreOrganizationProjectRequest $request, Organization $organization, CreateProject $create): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new ProjectResource($create->handle($user, $request->validated(), $organization)))->response()->setStatusCode(201);
    }
}
