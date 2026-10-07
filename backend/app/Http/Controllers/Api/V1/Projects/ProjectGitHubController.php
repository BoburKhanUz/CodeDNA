<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\GitHub\ChangeGitHubBranch;
use App\Actions\GitHub\ConnectGitHubRepository;
use App\Actions\GitHub\ConnectionLookup;
use App\Actions\GitHub\DisconnectGitHubRepository;
use App\Enums\GitHub\GitHubConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Errors\ErrorCode;
use App\Http\Requests\GitHub\ConnectGitHubRequest;
use App\Http\Requests\GitHub\ListGitHubPageRequest;
use App\Http\Requests\GitHub\UpdateGitHubRequest;
use App\Http\Resources\GitHubConnectionResource;
use App\Http\Resources\GitHubImportResource;
use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubErrors;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubSettings;
use App\Services\GitHub\GitHubUserAccess;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/github — a project's repository connection
 * (Phase 19). Owner-only (404 otherwise); archived projects can be read and
 * disconnected, but not connected, changed or imported.
 */
final class ProjectGitHubController extends Controller
{
    public function show(Project $project, Gate $gate, GitHubSettings $settings): JsonResponse
    {
        $gate->authorize('view', $project);
        /** @var User $user */
        $user = request()->user();
        $connection = $project->githubConnections()->where('status', GitHubConnectionStatus::Active->value)->first();
        $latest = $project->githubImports()->with('sourceSnapshot')->orderByDesc('created_at')->orderByDesc('id')->first();

        return response()->json(['data' => [
            'configured' => $settings->configured(),
            'account_connected' => GitHubAccount::query()->where('user_id', $user->getKey())->exists(),
            'connection' => $connection === null ? null : (new GitHubConnectionResource($connection))->toArray(request()),
            'latest_import' => $latest === null ? null : (new GitHubImportResource($latest))->toArray(request()),
        ]]);
    }

    public function store(ConnectGitHubRequest $request, Project $project, ConnectGitHubRepository $connect): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branch = $request->input('branch');
        $connection = $connect->handle($project, $user, $request->integer('repository_id'), is_string($branch) && $branch !== '' ? $branch : null);

        return (new GitHubConnectionResource($connection))->response()->setStatusCode(201);
    }

    public function update(UpdateGitHubRequest $request, Project $project, ChangeGitHubBranch $change): GitHubConnectionResource
    {
        /** @var User $user */
        $user = $request->user();

        return new GitHubConnectionResource($change->handle($project, $user, (string) $request->input('branch')));
    }

    public function destroy(Project $project, Gate $gate, DisconnectGitHubRepository $disconnect): GitHubConnectionResource
    {
        $gate->authorize('connectSource', $project);
        /** @var User $user */
        $user = request()->user();

        return new GitHubConnectionResource($disconnect->handle($project, $user));
    }

    /**
     * One page of the connected repository's branches, read from GitHub as the user.
     */
    public function branches(ListGitHubPageRequest $request, Project $project, Gate $gate, GitHubApi $api, GitHubUserAccess $access): JsonResponse
    {
        $gate->authorize('view', $project);
        /** @var User $user */
        $user = $request->user();
        $connection = ConnectionLookup::active($project);
        $token = $access->token($user);
        $repository = ConnectGitHubRepository::verifiedRepository($api, $token, $connection->repository_id);
        try {
            $page = $api->branches($token, $repository, $request->page(), $request->perPage());
        } catch (GitHubException $e) {
            throw GitHubErrors::api($e, ErrorCode::GitHubRepositoryNotFound, 'branches');
        }

        return response()->json([
            'data' => $page['branches'],
            'meta' => ['page' => $request->page(), 'per_page' => $request->perPage(), 'has_more' => $page['has_more'], 'default_branch' => $repository->defaultBranch],
        ]);
    }
}
