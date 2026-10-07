<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubErrors;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubNames;
use App\Services\GitHub\GitHubRepository;
use App\Services\GitHub\GitHubUserAccess;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Connects an ACTIVE project to one GitHub repository and branch
 * (docs/architecture/github-integration-v1.md#connection).
 *
 * The client sends only a repository ID and optionally a branch name. Both
 * are verified with GitHub before anything is stored:
 * 1. as the user: the repository must be visible to them through the App
 *    (GET /repositories/{id} with their token), and not disabled;
 * 2. the branch (default: the repository's default branch) must exist;
 * 3. as the App: the installation covering the repository is looked up;
 * 4. as the user: that installation must be one the user can access
 *    (Phase 21). A public repository is readable with any user token, so
 *    step 1 alone would let a user import through another organization's
 *    installation (its tokens and rate limit).
 * Owner, name, visibility and installation come from GitHub, never from the
 * request. One ACTIVE connection per project (unique index).
 */
final readonly class ConnectGitHubRepository
{
    public function __construct(
        private GitHubApi $api,
        private GitHubUserAccess $access,
        private ConnectionInterface $db,
    ) {}

    public function handle(Project $project, User $actor, int $repositoryId, ?string $branch): GitHubConnection
    {
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }
        if ($branch !== null && ! GitHubNames::isBranch($branch)) {
            throw ValidationException::withMessages(['branch' => 'The branch name is not valid.']);
        }
        $token = $this->access->token($actor);

        $repository = self::verifiedRepository($this->api, $token, $repositoryId);
        $branch ??= $repository->defaultBranch;
        self::verifyBranch($this->api, $token, $repository, $branch);
        try {
            $installationId = $this->api->repositoryInstallationId($repository);
        } catch (GitHubException $e) {
            throw GitHubErrors::api($e, ErrorCode::GitHubInstallationRequired, 'repository_installation');
        }
        self::verifyInstallationAccess($this->api, $token, $installationId);

        try {
            $connection = $this->db->transaction(function () use ($project, $repository, $branch, $installationId): GitHubConnection {
                $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->isActive()) {
                    throw new ApiException(ErrorCode::ProjectArchived);
                }
                if ($locked->githubConnections()->where('status', GitHubConnectionStatus::Active->value)->exists()) {
                    throw new ApiException(ErrorCode::GitHubAlreadyConnected);
                }
                $now = Carbon::now();
                $connection = new GitHubConnection;
                $connection->forceFill([
                    'project_id' => $locked->id,
                    'user_id' => $locked->user_id,
                    'installation_id' => $installationId,
                    'repository_id' => $repository->id,
                    'branch' => $branch,
                    'status' => GitHubConnectionStatus::Active,
                    'connected_at' => $now,
                    ...self::metadata($repository, $now),
                ])->save();

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::GitHubAlreadyConnected);
        }

        Log::info('github.connected', [
            'project_id' => $project->id,
            'connection_id' => $connection->id,
            'repository_id' => $repository->id,
            'user_id' => $actor->getKey(),
        ]);

        return $connection;
    }

    /**
     * The installation must be among those the user can access through the
     * App (GET /user/installations).
     *
     * @throws ApiException
     */
    public static function verifyInstallationAccess(GitHubApi $api, #[\SensitiveParameter] string $userToken, int $installationId): void
    {
        try {
            $installations = $api->installations($userToken);
        } catch (GitHubException $e) {
            throw GitHubErrors::api($e, ErrorCode::GitHubInstallationRequired, 'user_installations');
        }
        if (! in_array($installationId, array_column($installations, 'id'), true)) {
            throw new ApiException(ErrorCode::GitHubInstallationRequired);
        }
    }

    /**
     * The repository as the user sees it through the App.
     *
     * @throws ApiException
     */
    public static function verifiedRepository(GitHubApi $api, #[\SensitiveParameter] string $userToken, int $repositoryId): GitHubRepository
    {
        try {
            $repository = $api->repository($userToken, $repositoryId);
        } catch (GitHubException $e) {
            throw GitHubErrors::api($e, ErrorCode::GitHubRepositoryNotFound, 'repository');
        }
        if ($repository->disabled) {
            throw new ApiException(ErrorCode::GitHubRepositoryNotFound);
        }

        return $repository;
    }

    /**
     * @throws ApiException
     */
    public static function verifyBranch(GitHubApi $api, #[\SensitiveParameter] string $userToken, GitHubRepository $repository, string $branch): string
    {
        try {
            return $api->branchHead($userToken, $repository, $branch);
        } catch (GitHubException $e) {
            throw GitHubErrors::api($e, ErrorCode::GitHubBranchNotFound, 'branch');
        }
    }

    /**
     * The repository metadata kept on a connection.
     *
     * @return array<string, mixed>
     */
    public static function metadata(GitHubRepository $repository, Carbon $now): array
    {
        return [
            'repository_owner' => $repository->owner,
            'repository_name' => $repository->name,
            'repository_full_name' => $repository->fullName,
            'repository_private' => $repository->private,
            'repository_archived' => $repository->archived,
            'default_branch' => $repository->defaultBranch,
            'metadata_verified_at' => $now,
        ];
    }
}
