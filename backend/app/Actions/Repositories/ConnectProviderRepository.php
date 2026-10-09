<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\Billing\Feature;
use App\Enums\GitHub\GitHubConnectionStatus;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Project;
use App\Models\RepositoryProviderConnection;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\GitHub\GitHubNames;
use App\Services\Repositories\ProviderErrors;
use App\Services\Repositories\ProviderException;
use App\Services\Repositories\ProviderRepository;
use App\Services\Repositories\ProviderUserAccess;
use App\Services\Repositories\RepositoryProvider;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Connects a project to a GitLab or Bitbucket Cloud repository (Phase 28,
 * docs/integrations/provider-architecture.md#connection).
 *
 * The client sends a provider, a repository ID and optionally a branch. The
 * server verifies both with the provider, as the acting user, before storing
 * anything: the repository must be readable with the user's own token, and
 * the branch (default: the repository's default branch) must exist. Name,
 * visibility and branches always come from the provider. One repository
 * source per project, across GitHub, GitLab and Bitbucket.
 */
final readonly class ConnectProviderRepository
{
    public function __construct(
        private RepositoryProviders $providers,
        private ProviderUserAccess $access,
        private ConnectionInterface $db,
        private Entitlements $entitlements,
    ) {}

    public function handle(Project $project, User $actor, RepositoryProviderKey $key, string $repositoryId, ?string $branch): RepositoryProviderConnection
    {
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }
        $provider = $this->providers->get($key);
        // Billing (Phase 23): the project's plan must include repository integrations.
        $this->entitlements->require($project, Feature::GitHubIntegration);
        if ($branch !== null && ! GitHubNames::isBranch($branch)) {
            throw ValidationException::withMessages(['branch' => 'The branch name is not valid.']);
        }
        if (! $provider->isRepositoryId($repositoryId)) {
            throw new ApiException(ErrorCode::ProviderRepositoryNotFound, null, ['provider' => $key->value]);
        }
        $token = $this->access->token($provider, (string) $actor->getKey());
        $repository = self::verifiedRepository($provider, $token, $repositoryId);
        $branch ??= $repository->defaultBranch ?? throw new ApiException(ErrorCode::ProviderBranchNotFound, null, ['provider' => $key->value]);
        self::verifyBranch($provider, $token, $repository, $branch);

        try {
            $connection = $this->db->transaction(function () use ($project, $actor, $key, $repository, $branch): RepositoryProviderConnection {
                $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->isActive()) {
                    throw new ApiException(ErrorCode::ProjectArchived);
                }
                if (ProjectSource::hasActiveGitHub($locked) || ProjectSource::activeProviderConnection($locked) !== null) {
                    throw new ApiException(ErrorCode::SourceAlreadyConnected);
                }
                $now = Carbon::now();
                $connection = new RepositoryProviderConnection;
                $connection->forceFill([
                    'project_id' => $locked->id,
                    'provider' => $key,
                    'repository_id' => $repository->id,
                    'branch' => $branch,
                    'status' => GitHubConnectionStatus::Active,
                    'connected_by' => $actor->getKey(),
                    'connected_at' => $now,
                    ...self::metadata($repository, $now),
                ])->save();

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::SourceAlreadyConnected);
        }

        Log::info('repository_provider.connected', ['provider' => $key->value, 'project_id' => $project->id, 'connection_id' => $connection->id, 'user_id' => $actor->getKey()]);

        return $connection;
    }

    /**
     * The repository as the user sees it with their own token.
     *
     * @throws ApiException
     */
    public static function verifiedRepository(RepositoryProvider $provider, #[\SensitiveParameter] string $token, string $repositoryId): ProviderRepository
    {
        try {
            return $provider->repository($token, $repositoryId);
        } catch (ProviderException $e) {
            throw ProviderErrors::api($e, $provider->key(), ErrorCode::ProviderRepositoryNotFound, 'repository');
        }
    }

    /**
     * @throws ApiException
     */
    public static function verifyBranch(RepositoryProvider $provider, #[\SensitiveParameter] string $token, ProviderRepository $repository, string $branch): void
    {
        try {
            $provider->branchHead($token, $repository, $branch);
        } catch (ProviderException $e) {
            throw ProviderErrors::api($e, $provider->key(), ErrorCode::ProviderBranchNotFound, 'branch');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(ProviderRepository $repository, Carbon $now): array
    {
        return [
            'repository_full_name' => $repository->fullName,
            'repository_private' => $repository->private,
            'repository_archived' => $repository->archived,
            'default_branch' => $repository->defaultBranch,
            'metadata_verified_at' => $now,
        ];
    }
}
