<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Enums\GitHub\GitHubImportStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\GitHubConnection;
use App\Models\GitHubImport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Disconnects the project from GitHub (docs/architecture/github-integration-v1.md#disconnect).
 *
 * Only future GitHub access ends: the connection becomes DISCONNECTED and
 * frozen, and imports still waiting in the queue are CANCELLED (a running
 * import checks the connection before it records anything). Source
 * snapshots, analyses, CodeDNA, competencies, skill gaps and growth are
 * never touched. Allowed for archived projects too: it only removes access.
 */
final readonly class DisconnectGitHubRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function handle(Project $project, User $actor): GitHubConnection
    {
        $connection = $this->db->transaction(function () use ($project): GitHubConnection {
            $active = ConnectionLookup::active($project);
            $locked = GitHubConnection::query()->whereKey($active->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                throw new ApiException(ErrorCode::GitHubNotConnected);
            }
            $now = Carbon::now();
            GitHubImport::query()
                ->where('github_connection_id', $locked->id)
                ->where('status', GitHubImportStatus::Queued->value)
                ->update(['status' => GitHubImportStatus::Cancelled->value, 'completed_at' => $now, 'updated_at' => $now]);
            $locked->forceFill(['status' => GitHubConnectionStatus::Disconnected, 'disconnected_at' => $now])->save();

            return $locked;
        });

        Log::info('github.disconnected', ['project_id' => $project->id, 'connection_id' => $connection->id, 'user_id' => $actor->getKey()]);

        return $connection;
    }
}
