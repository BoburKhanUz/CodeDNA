<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\UpdateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ListProjectsRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects — the authenticated developer's projects.
 *
 * There is no DELETE: projects leave the active list through
 * POST /api/v1/projects/{project}/archive (ArchiveProjectController).
 */
final class ProjectController extends Controller
{
    public function index(ListProjectsRequest $request): PaginatedCollection
    {
        /** @var User $user */
        $user = $request->user();

        $projects = $user->projects()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($projects, ProjectResource::class);
    }

    public function store(StoreProjectRequest $request, CreateProject $createProject): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new ProjectResource($createProject->handle($user, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, Gate $gate): ProjectResource
    {
        $gate->authorize('view', $project);

        return new ProjectResource($project);
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $updateProject): ProjectResource
    {
        return new ProjectResource($updateProject->handle($project, $request->validated()));
    }
}
