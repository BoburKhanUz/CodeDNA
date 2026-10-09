<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Project;
use App\Models\RepositoryProviderConnection;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Disconnects the project's GitLab or Bitbucket repository (Phase 28). Works
 * for archived projects and without contacting the provider. Snapshots,
 * analyses and imports are kept; an import still queued or running ends
 * CANCELLED without recording anything.
 */
final readonly class DisconnectProviderRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function handle(Project $project, User $actor): RepositoryProviderConnection
    {
        $connection = $this->db->transaction(function () use ($project): RepositoryProviderConnection {
            Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $current = RepositoryProviderConnection::query()->where('project_id', $project->getKey())
                ->where('status', GitHubConnectionStatus::Active->value)->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::ProviderNotConnected);
            $current->forceFill(['status' => GitHubConnectionStatus::Disconnected, 'disconnected_at' => Carbon::now()])->save();

            return $current;
        });
        Log::info('repository_provider.disconnected', ['provider' => $connection->provider->value, 'project_id' => $project->id, 'connection_id' => $connection->id, 'user_id' => $actor->getKey()]);

        return $connection;
    }
}
