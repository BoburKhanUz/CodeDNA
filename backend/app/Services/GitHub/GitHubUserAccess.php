<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\GitHubAccount;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * A usable GitHub user access token for a CodeDNA user: the stored one,
 * refreshed (with a row lock, because GitHub rotates refresh tokens) when it
 * expires within a minute. Without an authorization, or when GitHub refuses
 * the refresh, the user must authorize again (GITHUB_AUTH_REQUIRED).
 */
final readonly class GitHubUserAccess
{
    public function __construct(
        private GitHubApi $api,
        private GitHubSettings $settings,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws ApiException
     */
    public function token(User $user): string
    {
        if (! $this->settings->configured()) {
            throw new ApiException(ErrorCode::GitHubNotConfigured);
        }
        $account = GitHubAccount::query()->where('user_id', $user->getKey())->first()
            ?? throw new ApiException(ErrorCode::GitHubAuthRequired);
        if (! $this->expiring($account)) {
            return $account->access_token;
        }

        return $this->db->transaction(function () use ($user): string {
            $locked = GitHubAccount::query()->where('user_id', $user->getKey())->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::GitHubAuthRequired);
            if (! $this->expiring($locked)) {
                return $locked->access_token;
            }
            $refresh = $locked->refresh_token;
            if ($refresh === null || ($locked->refresh_token_expires_at !== null && $locked->refresh_token_expires_at->isPast())) {
                throw new ApiException(ErrorCode::GitHubAuthRequired);
            }
            try {
                $tokens = $this->api->refresh($refresh);
            } catch (GitHubException $e) {
                throw GitHubErrors::api($e, ErrorCode::GitHubAuthRequired, 'refresh_user_token');
            }
            $locked->forceFill([
                'access_token' => $tokens->accessToken,
                'access_token_expires_at' => $tokens->accessTokenExpiresAt,
                'refresh_token' => $tokens->refreshToken ?? $refresh,
                'refresh_token_expires_at' => $tokens->refreshToken === null ? $locked->refresh_token_expires_at : $tokens->refreshTokenExpiresAt,
            ])->save();

            return $tokens->accessToken;
        });
    }

    private function expiring(GitHubAccount $account): bool
    {
        return $account->access_token_expires_at !== null && $account->access_token_expires_at->lte(Carbon::now()->addMinute());
    }
}
