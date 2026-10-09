<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Repositories;

use App\Actions\Repositories\CompleteProviderAuthorization;
use App\Actions\Repositories\StartProviderAuthorization;
use App\Actions\Repositories\UnlinkProviderAccount;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Errors\ErrorCode;
use App\Http\Requests\GitHub\ListGitHubPageRequest;
use App\Http\Requests\Repositories\CompleteProviderAuthorizationRequest;
use App\Http\Requests\Repositories\StartProviderAuthorizationRequest;
use App\Models\Project;
use App\Models\RepositoryProviderAccount;
use App\Models\User;
use App\Services\Repositories\ProviderErrors;
use App\Services\Repositories\ProviderException;
use App\Services\Repositories\ProviderRepository;
use App\Services\Repositories\ProviderUserAccess;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/repository-providers — the signed-in user's GitLab and Bitbucket
 * Cloud authorizations and the repositories they can read (Phase 28,
 * docs/api/README.md#gitlab-and-bitbucket-cloud). Tokens never leave the
 * server; repositories are always read from the provider as the user.
 */
final class RepositoryProviderAccountController extends Controller
{
    public function index(RepositoryProviders $providers): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();
        $accounts = RepositoryProviderAccount::query()->where('user_id', $user->getKey())->get()->keyBy(fn ($a) => $a->provider->value);

        return response()->json(['data' => array_map(function (RepositoryProviderKey $key) use ($providers, $accounts): array {
            $account = $accounts->get($key->value);

            return [
                'provider' => $key->value,
                'name' => $key->label(),
                'configured' => $providers->settings($key)->configured(),
                // The installation this GitLab provider points at (GitLab.com or a self-managed instance).
                'host' => parse_url($providers->settings($key)->webUrl, PHP_URL_HOST),
                'account' => $account === null ? null : [
                    'username' => $account->username,
                    'connected_at' => $account->created_at?->toIso8601ZuluString(),
                ],
            ];
        }, RepositoryProviderKey::cases())]);
    }

    public function startAuthorization(StartProviderAuthorizationRequest $request, RepositoryProviderKey $provider, StartProviderAuthorization $start, Gate $gate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = null;
        $projectId = $request->input('project_id');
        if (is_string($projectId) && $projectId !== '') {
            $project = Project::query()->find(strtolower($projectId)) ?? throw new ApiException(ErrorCode::ResourceNotFound);
            $gate->authorize('connectSource', $project);
        }

        return response()->json(['data' => $start->handle($user, $provider, $project)], 201);
    }

    public function callback(CompleteProviderAuthorizationRequest $request, RepositoryProviderKey $provider, CompleteProviderAuthorization $complete, Gate $gate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $projectId = $complete->handle($user, $provider, (string) $request->input('state'), (string) $request->input('code'));
        // Return to the project only if the user may still connect it.
        $project = $projectId === null ? null : Project::query()->find($projectId);
        $returnTo = $project !== null && $gate->forUser($user)->allows('connectSource', $project) ? $project->id : null;

        return response()->json(['data' => ['provider' => $provider->value, 'connected' => true, 'project_id' => $returnTo]]);
    }

    public function destroy(RepositoryProviderKey $provider, UnlinkProviderAccount $unlink): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        return response()->json(['data' => ['provider' => $provider->value, 'revocation' => $unlink->handle($user, $provider)]]);
    }

    /**
     * One page of the repositories the user can read on the provider.
     */
    public function repositories(ListGitHubPageRequest $request, RepositoryProviderKey $provider, RepositoryProviders $providers, ProviderUserAccess $access): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $adapter = $providers->get($provider);
        try {
            $page = $adapter->repositories($access->token($adapter, (string) $user->getKey()), $request->page(), $request->perPage());
        } catch (ProviderException $e) {
            throw ProviderErrors::api($e, $provider, ErrorCode::ProviderAuthRequired, 'repositories');
        }

        return response()->json([
            'data' => array_map(static fn (ProviderRepository $r): array => $r->toArray(), $page['repositories']),
            'meta' => ['page' => $request->page(), 'per_page' => $request->perPage(), 'has_more' => $page['has_more']],
        ]);
    }
}
