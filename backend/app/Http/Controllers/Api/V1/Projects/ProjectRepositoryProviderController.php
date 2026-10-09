<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Repositories\ChangeProviderBranch;
use App\Actions\Repositories\ConnectProviderRepository;
use App\Actions\Repositories\DisconnectProviderRepository;
use App\Actions\Repositories\ProjectSource;
use App\Actions\Repositories\RequestProviderImport;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Http\Controllers\Controller;
use App\Http\Errors\ErrorCode;
use App\Http\Pagination\KeysetPaginator;
use App\Http\Requests\GitHub\ListGitHubImportsRequest;
use App\Http\Requests\GitHub\ListGitHubPageRequest;
use App\Http\Requests\GitHub\StoreGitHubImportRequest;
use App\Http\Requests\Repositories\ConnectProviderRequest;
use App\Http\Requests\Repositories\UpdateProviderRequest;
use App\Http\Resources\CursorCollection;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\RepositoryProviderConnectionResource;
use App\Http\Resources\RepositoryProviderImportResource;
use App\Models\Project;
use App\Models\RepositoryProviderAccount;
use App\Models\RepositoryProviderImport;
use App\Models\User;
use App\Services\Repositories\ProviderErrors;
use App\Services\Repositories\ProviderException;
use App\Services\Repositories\ProviderUserAccess;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/repository-provider — a project's GitLab or
 * Bitbucket Cloud repository and its imports (Phase 28). Reads need view
 * access; connecting, changing, disconnecting and importing need
 * connectSource (the owner, or an admin of a team project). Archived
 * projects can be read and disconnected only.
 */
final class ProjectRepositoryProviderController extends Controller
{
    public function show(Project $project, Gate $gate, RepositoryProviders $providers): JsonResponse
    {
        $gate->authorize('view', $project);
        /** @var User $user */
        $user = request()->user();
        $connection = ProjectSource::activeProviderConnection($project);
        $latest = RepositoryProviderImport::query()->where('project_id', $project->id)->with('sourceSnapshot')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $linked = RepositoryProviderAccount::query()->where('user_id', $user->getKey())->pluck('provider')->map(fn ($p) => $p->value)->all();

        return response()->json(['data' => [
            'providers' => array_map(static fn (RepositoryProviderKey $key): array => [
                'provider' => $key->value,
                'name' => $key->label(),
                'configured' => $providers->settings($key)->configured(),
                'account_connected' => in_array($key->value, $linked, true),
            ], RepositoryProviderKey::cases()),
            'github_connected' => ProjectSource::hasActiveGitHub($project),
            'connection' => $connection === null ? null : (new RepositoryProviderConnectionResource($connection))->toArray(request()),
            'latest_import' => $latest === null ? null : (new RepositoryProviderImportResource($latest))->toArray(request()),
        ]]);
    }

    public function store(ConnectProviderRequest $request, Project $project, ConnectProviderRepository $connect): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branch = $request->input('branch');
        $connection = $connect->handle($project, $user, RepositoryProviderKey::from((string) $request->input('provider')),
            (string) $request->input('repository_id'), is_string($branch) && $branch !== '' ? $branch : null);

        return (new RepositoryProviderConnectionResource($connection))->response()->setStatusCode(201);
    }

    public function update(UpdateProviderRequest $request, Project $project, ChangeProviderBranch $change): RepositoryProviderConnectionResource
    {
        /** @var User $user */
        $user = $request->user();

        return new RepositoryProviderConnectionResource($change->handle($project, $user, (string) $request->input('branch')));
    }

    public function destroy(Project $project, Gate $gate, DisconnectProviderRepository $disconnect): RepositoryProviderConnectionResource
    {
        $gate->authorize('connectSource', $project);
        /** @var User $user */
        $user = request()->user();

        return new RepositoryProviderConnectionResource($disconnect->handle($project, $user));
    }

    /** One page of the connected repository's branches, read from the provider as the user. */
    public function branches(ListGitHubPageRequest $request, Project $project, Gate $gate, RepositoryProviders $providers, ProviderUserAccess $access): JsonResponse
    {
        $gate->authorize('view', $project);
        /** @var User $user */
        $user = $request->user();
        $connection = ProjectSource::requireProviderConnection($project);
        $provider = $providers->get($connection->provider);
        $token = $access->token($provider, (string) $user->getKey());
        $repository = ConnectProviderRepository::verifiedRepository($provider, $token, $connection->repository_id);
        try {
            $page = $provider->branches($token, $repository, $request->page(), $request->perPage());
        } catch (ProviderException $e) {
            throw ProviderErrors::api($e, $provider->key(), ErrorCode::ProviderRepositoryNotFound, 'branches');
        }

        return response()->json([
            'data' => $page['branches'],
            'meta' => ['page' => $request->page(), 'per_page' => $request->perPage(), 'has_more' => $page['has_more'], 'default_branch' => $repository->defaultBranch],
        ]);
    }

    public function imports(ListGitHubImportsRequest $request, Project $project, Gate $gate, KeysetPaginator $keyset): PaginatedCollection|CursorCollection
    {
        $gate->authorize('view', $project);
        $query = RepositoryProviderImport::query()->where('project_id', $project->id)->with('sourceSnapshot');
        if ($request->usesCursor()) {
            return new CursorCollection($keyset->paginate(
                $query, ['repository_provider_imports.created_at', 'repository_provider_imports.id'],
                fn (RepositoryProviderImport $import): array => [(string) $import->getRawOriginal('created_at'), $import->id],
                'provider-imports:'.$project->id, $request->cursor(), $request->perPage(), indexPrefix: 1,
            ), RepositoryProviderImportResource::class);
        }

        return new PaginatedCollection($query->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage(), page: $request->page()), RepositoryProviderImportResource::class);
    }

    public function showImport(Project $project, string $import, Gate $gate): RepositoryProviderImportResource
    {
        $gate->authorize('view', $project);
        // Scoped to the project: another project's import is not found.
        $found = RepositoryProviderImport::query()->where('project_id', $project->id)->whereKey($import)->with('sourceSnapshot')->firstOrFail();

        return new RepositoryProviderImportResource($found);
    }

    public function storeImport(StoreGitHubImportRequest $request, Project $project, RequestProviderImport $requestImport): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $requestImport->handle($project, $user);
        $response = (new RepositoryProviderImportResource($result->import->loadMissing('sourceSnapshot')))->response();

        return $result->created
            ? $response->setStatusCode(202)
            : $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }
}
