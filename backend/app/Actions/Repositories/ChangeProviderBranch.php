<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Project;
use App\Models\RepositoryProviderConnection;
use App\Models\User;
use App\Services\GitHub\GitHubNames;
use App\Services\Repositories\ProviderUserAccess;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Changes the connected branch (Phase 28) after verifying it with the
 * provider as the acting user. Never switches branches on its own.
 */
final readonly class ChangeProviderBranch
{
    public function __construct(
        private RepositoryProviders $providers,
        private ProviderUserAccess $access,
        private ConnectionInterface $db,
    ) {}

    public function handle(Project $project, User $actor, string $branch): RepositoryProviderConnection
    {
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }
        if (! GitHubNames::isBranch($branch)) {
            throw ValidationException::withMessages(['branch' => 'The branch name is not valid.']);
        }
        $connection = ProjectSource::requireProviderConnection($project);
        $provider = $this->providers->get($connection->provider);
        $token = $this->access->token($provider, (string) $actor->getKey());
        $repository = ConnectProviderRepository::verifiedRepository($provider, $token, $connection->repository_id);
        ConnectProviderRepository::verifyBranch($provider, $token, $repository, $branch);

        return $this->db->transaction(function () use ($connection, $repository, $branch): RepositoryProviderConnection {
            $current = RepositoryProviderConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if (! $current->isActive()) {
                throw new ApiException(ErrorCode::ProviderNotConnected);
            }
            $current->forceFill(['branch' => $branch, ...ConnectProviderRepository::metadata($repository, Carbon::now())])->save();

            return $current;
        });
    }
}
