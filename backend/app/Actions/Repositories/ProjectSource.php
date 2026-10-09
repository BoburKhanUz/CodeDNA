<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\RepositoryProviderConnection;

/**
 * One repository source per project, whatever its provider (Phase 28).
 * Checked by every connect action while it holds the project row lock, so
 * a GitHub and a GitLab/Bitbucket connection can never both be ACTIVE.
 */
final class ProjectSource
{
    public static function hasActiveGitHub(Project $project): bool
    {
        return GitHubConnection::query()->where('project_id', $project->getKey())->where('status', GitHubConnectionStatus::Active->value)->exists();
    }

    public static function activeProviderConnection(Project $project): ?RepositoryProviderConnection
    {
        return RepositoryProviderConnection::query()->where('project_id', $project->getKey())->where('status', GitHubConnectionStatus::Active->value)->first();
    }

    /**
     * @throws ApiException PROVIDER_NOT_CONNECTED
     */
    public static function requireProviderConnection(Project $project): RepositoryProviderConnection
    {
        return self::activeProviderConnection($project) ?? throw new ApiException(ErrorCode::ProviderNotConnected);
    }
}
