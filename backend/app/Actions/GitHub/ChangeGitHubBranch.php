<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubNames;
use App\Services\GitHub\GitHubUserAccess;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Changes the branch future imports read. The branch is verified with
 * GitHub as the user; the repository's metadata is refreshed at the same
 * time (a renamed repository keeps its ID). Past imports keep their branch.
 */
final readonly class ChangeGitHubBranch
{
    public function __construct(
        private GitHubApi $api,
        private GitHubUserAccess $access,
        private ConnectionInterface $db,
    ) {}

    public function handle(Project $project, User $actor, string $branch): GitHubConnection
    {
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }
        if (! GitHubNames::isBranch($branch)) {
            throw ValidationException::withMessages(['branch' => 'The branch name is not valid.']);
        }
        $connection = ConnectionLookup::active($project);
        $token = $this->access->token($actor);
        $repository = ConnectGitHubRepository::verifiedRepository($this->api, $token, $connection->repository_id);
        ConnectGitHubRepository::verifyBranch($this->api, $token, $repository, $branch);

        $updated = $this->db->transaction(function () use ($project, $connection, $repository, $branch): GitHubConnection {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            if (! $lockedProject->isActive()) {
                throw new ApiException(ErrorCode::ProjectArchived);
            }
            $locked = GitHubConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                throw new ApiException(ErrorCode::GitHubNotConnected);
            }
            $locked->forceFill(['branch' => $branch, ...ConnectGitHubRepository::metadata($repository, Carbon::now())])->save();

            return $locked;
        });

        Log::info('github.branch_changed', ['project_id' => $project->id, 'connection_id' => $updated->id, 'user_id' => $actor->getKey()]);

        return $updated;
    }
}
