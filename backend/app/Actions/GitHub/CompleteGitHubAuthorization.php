<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\GitHubAccount;
use App\Models\User;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubErrors;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubSettings;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Completes a GitHub authorization with the code and state GitHub sent the
 * browser back with (docs/architecture/github-integration-v1.md#authorization).
 *
 * 1. The state is consumed atomically: it must exist, belong to this user,
 *    be unexpired and unused. A single UPDATE … RETURNING makes a replay or
 *    a concurrent second use fail. A state presented by another user is not
 *    consumed and fails the same way.
 * 2. The code is exchanged server-side for the user's tokens.
 * 3. GitHub says who the user is; the tokens are stored encrypted.
 *
 * Anything else in the callback (installation_id, setup_action) is ignored:
 * installations and repositories are always read from GitHub as the user.
 *
 * @return string|null the project to return to, if the state named one
 */
final readonly class CompleteGitHubAuthorization
{
    public function __construct(
        private GitHubApi $api,
        private GitHubSettings $settings,
        private ConnectionInterface $db,
    ) {}

    public function handle(User $user, string $state, string $code): ?string
    {
        if (! $this->settings->configured()) {
            throw new ApiException(ErrorCode::GitHubNotConfigured);
        }

        $consumed = $this->db->selectOne(
            'UPDATE github_oauth_states SET consumed_at = ? WHERE state_hash = ? AND user_id = ? AND consumed_at IS NULL AND expires_at > ? RETURNING project_id',
            [Carbon::now(), hash('sha256', $state), $user->getKey(), Carbon::now()],
        );
        if ($consumed === null) {
            Log::warning('github.state_rejected', ['user_id' => $user->getKey()]);

            throw new ApiException(ErrorCode::GitHubStateInvalid);
        }

        try {
            $tokens = $this->api->exchangeCode($code);
            $identity = $this->api->user($tokens->accessToken);
        } catch (GitHubException $e) {
            throw GitHubErrors::api($e, ErrorCode::GitHubAuthRequired, 'complete_authorization');
        }

        $account = GitHubAccount::query()->where('user_id', $user->getKey())->first() ?? new GitHubAccount;
        $account->forceFill([
            'user_id' => $user->getKey(),
            'github_user_id' => $identity['id'],
            'login' => $identity['login'],
            'access_token' => $tokens->accessToken,
            'access_token_expires_at' => $tokens->accessTokenExpiresAt,
            'refresh_token' => $tokens->refreshToken,
            'refresh_token_expires_at' => $tokens->refreshTokenExpiresAt,
        ])->save();

        Log::info('github.authorized', ['user_id' => $user->getKey(), 'github_user_id' => $identity['id']]);

        return $consumed->project_id;
    }
}
