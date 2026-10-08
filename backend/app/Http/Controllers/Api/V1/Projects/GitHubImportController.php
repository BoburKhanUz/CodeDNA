<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\GitHub\RequestGitHubImport;
use App\Http\Controllers\Controller;
use App\Http\Pagination\KeysetPaginator;
use App\Http\Requests\GitHub\ListGitHubImportsRequest;
use App\Http\Requests\GitHub\StoreGitHubImportRequest;
use App\Http\Resources\CursorCollection;
use App\Http\Resources\GitHubImportResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\GitHubImport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/github/imports — imports of the connected
 * repository into source snapshots (Phase 19). Owner-only.
 */
final class GitHubImportController extends Controller
{
    public function index(ListGitHubImportsRequest $request, Project $project, Gate $gate, KeysetPaginator $keyset): PaginatedCollection|CursorCollection
    {
        $gate->authorize('view', $project);

        if ($request->usesCursor()) {
            return new CursorCollection($keyset->paginate(
                $project->githubImports()->getQuery()->with('sourceSnapshot'), ['github_imports.created_at', 'github_imports.id'],
                fn (GitHubImport $import): array => [(string) $import->getRawOriginal('created_at'), $import->id],
                'github-imports:'.$project->id, $request->cursor(), $request->perPage(), indexPrefix: 1,
            ), GitHubImportResource::class);
        }

        $imports = $project->githubImports()
            ->with('sourceSnapshot')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($imports, GitHubImportResource::class);
    }

    public function show(Project $project, GitHubImport $githubImport, Gate $gate): GitHubImportResource
    {
        // Scoped binding: the import belongs to this project.
        $gate->authorize('view', $project);

        return new GitHubImportResource($githubImport->loadMissing('sourceSnapshot'));
    }

    public function store(StoreGitHubImportRequest $request, Project $project, RequestGitHubImport $requestImport): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $requestImport->handle($project, $user);
        $response = (new GitHubImportResource($result->import->loadMissing('sourceSnapshot')))->response();

        return $result->created
            ? $response->setStatusCode(202)
            : $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }
}
