<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Enums\GitHub\GitHubFailure;
use App\Enums\GitHub\GitHubImportStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Jobs\ImportGitHubSource;
use App\Models\GitHubConnection;
use App\Models\GitHubImport;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubUserAccess;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Queues an import of the connected repository's branch
 * (docs/architecture/github-integration-v1.md#import-lifecycle).
 *
 * The request carries nothing: repository and branch come from the stored
 * connection, and the commit is resolved by the job from GitHub. Before
 * queueing, GitHub confirms that the user can still see the repository.
 * At most one import per project is in progress (unique index); asking
 * again returns it. An import stuck for import_stale_after_seconds is
 * failed so it no longer blocks.
 */
final readonly class RequestGitHubImport
{
    public function __construct(
        private GitHubApi $api,
        private GitHubUserAccess $access,
        private ConnectionInterface $db,
        private Repository $config,
    ) {}

    public function handle(Project $project, User $actor): RequestedGitHubImport
    {
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }
        $connection = ConnectionLookup::active($project);
        $token = $this->access->token($actor);
        ConnectGitHubRepository::verifiedRepository($this->api, $token, $connection->repository_id);

        try {
            $result = $this->db->transaction(fn (): RequestedGitHubImport => $this->queue($project, $connection));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request queued one first: that import is the answer.
            $active = GitHubImport::query()->where('project_id', $project->id)
                ->whereIn('status', GitHubImportStatus::activeValues())->first()
                ?? throw new ApiException(ErrorCode::InternalError);

            return new RequestedGitHubImport($active, false);
        }

        if ($result->created) {
            ImportGitHubSource::dispatch($result->import->id)->afterCommit();
            Log::info('github.import_queued', [
                'project_id' => $project->id,
                'import_id' => $result->import->id,
                'connection_id' => $connection->id,
                'user_id' => $actor->getKey(),
            ]);
        }

        return $result;
    }

    private function queue(Project $project, GitHubConnection $connection): RequestedGitHubImport
    {
        $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }
        $current = GitHubConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
        if (! $current->isActive()) {
            throw new ApiException(ErrorCode::GitHubNotConnected);
        }

        $now = Carbon::now();
        $active = GitHubImport::query()->where('project_id', $locked->id)
            ->whereIn('status', GitHubImportStatus::activeValues())->lockForUpdate()->first();
        if ($active !== null) {
            $staleAfter = (int) $this->config->get('codedna.github.import_stale_after_seconds');
            if ($active->updated_at !== null && $active->updated_at->gt($now->copy()->subSeconds($staleAfter))) {
                return new RequestedGitHubImport($active, false);
            }
            $active->forceFill(['status' => GitHubImportStatus::Failed, 'failure_code' => GitHubFailure::ImportFailed->value, 'completed_at' => $now])->save();
            Log::warning('github.import_stale', ['project_id' => $locked->id, 'import_id' => $active->id]);
        }

        $import = new GitHubImport;
        $import->forceFill([
            'github_connection_id' => $current->id,
            'project_id' => $current->project_id,
            'user_id' => $current->user_id,
            'repository_id' => $current->repository_id,
            'repository_full_name' => $current->repository_full_name,
            'ref' => $current->branch,
            'status' => GitHubImportStatus::Queued,
        ])->save();

        return new RequestedGitHubImport($import, true);
    }
}
