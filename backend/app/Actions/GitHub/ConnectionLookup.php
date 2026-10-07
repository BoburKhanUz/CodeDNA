<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\GitHubConnection;
use App\Models\Project;

/**
 * The project's ACTIVE GitHub connection, or GITHUB_NOT_CONNECTED.
 */
final class ConnectionLookup
{
    public static function active(Project $project): GitHubConnection
    {
        return $project->githubConnections()->where('status', GitHubConnectionStatus::Active->value)->first()
            ?? throw new ApiException(ErrorCode::GitHubNotConnected);
    }
}
