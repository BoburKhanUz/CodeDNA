<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Models\GitHubAccount;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * GitHub state for tests: an authorized account and a connection, written
 * directly (the API tests cover how they are made).
 */
final class GitHubFixtures
{
    public static function account(User $user, string $token = FakeGitHub::USER_TOKEN): GitHubAccount
    {
        $account = new GitHubAccount;
        $account->forceFill([
            'user_id' => $user->id,
            'github_user_id' => 4242,
            'login' => 'octo-dev',
            'access_token' => $token,
            'access_token_expires_at' => Carbon::now()->addHours(8),
            'refresh_token' => FakeGitHub::REFRESH_TOKEN,
            'refresh_token_expires_at' => Carbon::now()->addMonths(6),
        ])->save();

        return $account;
    }

    public static function connection(Project $project, string $branch = 'main', int $repositoryId = FakeGitHub::REPOSITORY_ID): GitHubConnection
    {
        $now = Carbon::now();
        $connection = new GitHubConnection;
        $connection->forceFill([
            'project_id' => $project->id,
            'user_id' => $project->user_id,
            'installation_id' => FakeGitHub::INSTALLATION_ID,
            'repository_id' => $repositoryId,
            'repository_owner' => 'octo-org',
            'repository_name' => 'billing-service',
            'repository_full_name' => 'octo-org/billing-service',
            'repository_private' => true,
            'repository_archived' => false,
            'default_branch' => 'main',
            'branch' => $branch,
            'status' => GitHubConnectionStatus::Active,
            'metadata_verified_at' => $now,
            'connected_at' => $now,
        ])->save();

        return $connection;
    }
}
