<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\GitHub;

use App\Actions\GitHub\CompleteGitHubAuthorization;
use App\Actions\GitHub\StartGitHubAuthorization;
use App\Actions\GitHub\UnlinkGitHubAccount;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Errors\ErrorCode;
use App\Http\Requests\GitHub\CompleteGitHubAuthorizationRequest;
use App\Http\Requests\GitHub\ListGitHubPageRequest;
use App\Http\Requests\GitHub\StartGitHubAuthorizationRequest;
use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubErrors;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubRepository;
use App\Services\GitHub\GitHubSettings;
use App\Services\GitHub\GitHubUserAccess;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * /api/v1/github — the signed-in user's GitHub authorization and what it
 * can reach (Phase 19, docs/api/README.md#github-integration). Tokens never
 * leave the server; installations and repositories are always read from
 * GitHub as the user.
 */
final class GitHubAccountController extends Controller
{
    public function show(GitHubSettings $settings): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();
        $account = GitHubAccount::query()->where('user_id', $user->getKey())->first();

        return response()->json(['data' => [
            'configured' => $settings->configured(),
            'account' => $account === null ? null : [
                'login' => $account->login,
                'connected_at' => $account->created_at?->toIso8601ZuluString(),
            ],
        ]]);
    }

    public function startAuthorization(StartGitHubAuthorizationRequest $request, StartGitHubAuthorization $start, Gate $gate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = null;
        $projectId = $request->input('project_id');
        if (is_string($projectId) && $projectId !== '') {
            $project = Project::query()->find(strtolower($projectId)) ?? throw new ApiException(ErrorCode::ResourceNotFound);
            $gate->authorize('connectSource', $project);
        }

        return response()->json(['data' => $start->handle($user, $project)], 201);
    }

    public function callback(CompleteGitHubAuthorizationRequest $request, CompleteGitHubAuthorization $complete): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $projectId = $complete->handle($user, (string) $request->input('state'), (string) $request->input('code'));

        return response()->json(['data' => ['connected' => true, 'project_id' => $projectId]]);
    }

    public function destroy(UnlinkGitHubAccount $unlink): Response
    {
        /** @var User $user */
        $user = request()->user();
        $unlink->handle($user);

        return response()->noContent();
    }

    public function installations(GitHubApi $api, GitHubUserAccess $access): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();
        try {
            $installations = $api->installations($access->token($user));
        } catch (GitHubException $e) {
            throw GitHubErrors::api($e, ErrorCode::GitHubAuthRequired, 'installations');
        }

        return response()->json(['data' => $installations]);
    }

    /**
     * One page of the repositories the user can reach in an installation.
     * The installation ID is only a lookup key: GitHub refuses it (404 here)
     * when it is not one of this user's installations.
     */
    public function repositories(ListGitHubPageRequest $request, int $installation, GitHubApi $api, GitHubUserAccess $access): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $page = $api->installationRepositories($access->token($user), $installation, $request->page(), $request->perPage());
        } catch (GitHubException $e) {
            throw GitHubErrors::api($e, ErrorCode::ResourceNotFound, 'installation_repositories');
        }

        return response()->json([
            'data' => array_map(static fn (GitHubRepository $r): array => $r->toArray(), $page['repositories']),
            'meta' => ['page' => $request->page(), 'per_page' => $request->perPage(), 'has_more' => $page['has_more']],
        ]);
    }
}
