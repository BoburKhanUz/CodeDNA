<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Models\Project;
use App\Models\RepositoryProviderAccount;
use App\Models\RepositoryProviderConnection;
use App\Models\User;
use Illuminate\Support\Carbon;

/** GitLab / Bitbucket Cloud fixtures (Phase 28), matching FakeProviders. */
final class ProviderFixtures
{
    public static function account(User $user, RepositoryProviderKey $provider, string $token = FakeProviders::ACCESS_TOKEN, ?Carbon $expiresAt = null, ?string $providerUserId = null): RepositoryProviderAccount
    {
        $account = new RepositoryProviderAccount;
        $account->forceFill([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId ?? ($provider === RepositoryProviderKey::GitLab ? '9001' : '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}'),
            'username' => 'ada',
            'access_token' => $token,
            'access_token_expires_at' => $expiresAt ?? Carbon::now()->addHour(),
            'refresh_token' => FakeProviders::REFRESH_TOKEN,
        ])->save();

        return $account;
    }

    public static function repositoryId(RepositoryProviderKey $provider): string
    {
        return $provider === RepositoryProviderKey::GitLab ? (string) FakeProviders::GITLAB_PROJECT : FakeProviders::bitbucketId();
    }

    public static function connection(Project $project, RepositoryProviderKey $provider, ?User $by = null, string $branch = 'main'): RepositoryProviderConnection
    {
        $connection = new RepositoryProviderConnection;
        $now = Carbon::now();
        $connection->forceFill([
            'project_id' => $project->id,
            'provider' => $provider,
            'repository_id' => self::repositoryId($provider),
            'repository_full_name' => $provider === RepositoryProviderKey::GitLab ? 'acme/billing-service' : 'acme/billing-service',
            'repository_private' => true,
            'repository_archived' => false,
            'default_branch' => 'main',
            'branch' => $branch,
            'status' => GitHubConnectionStatus::Active,
            'connected_by' => ($by ?? User::query()->findOrFail($project->user_id))->getKey(),
            'connected_at' => $now,
            'metadata_verified_at' => $now,
        ])->save();

        return $connection;
    }
}
