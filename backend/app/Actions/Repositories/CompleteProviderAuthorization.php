<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\RepositoryProviderAccount;
use App\Models\User;
use App\Services\Repositories\ProviderErrors;
use App\Services\Repositories\ProviderException;
use App\Services\Repositories\ProviderIdentity;
use App\Services\Repositories\ProviderTokens;
use App\Services\Repositories\RepositoryProvider;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Completes a GitLab or Bitbucket Cloud authorization with the code and
 * state the provider sent the browser back with (Phase 28,
 * docs/integrations/oauth-setup.md#the-flow).
 *
 * 1. The state is consumed atomically: it must exist, belong to this user
 *    AND this provider, be unexpired and unused (one UPDATE … RETURNING).
 *    A replay, a concurrent second use, another user's state or another
 *    provider's state all fail with PROVIDER_STATE_INVALID; a state presented
 *    by the wrong user or for the wrong provider is not consumed.
 * 2. The code is exchanged server-side (client secret never leaves it).
 * 3. The provider says who the user is. An identity already linked to
 *    another CodeDNA user is refused (PROVIDER_ACCOUNT_IN_USE): never taken
 *    over. The tokens just obtained are then revoked where the provider
 *    supports it, and never stored.
 * 4. The tokens are stored encrypted (one account per user and provider).
 *
 * @return string|null the project to return to, if the state named one
 */
final readonly class CompleteProviderAuthorization
{
    public function __construct(private RepositoryProviders $providers, private ConnectionInterface $db) {}

    public function handle(User $user, RepositoryProviderKey $key, #[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code): ?string
    {
        $provider = $this->providers->get($key);
        $consumed = $this->db->selectOne(
            'UPDATE repository_provider_oauth_states SET consumed_at = ? WHERE state_hash = ? AND user_id = ? AND provider = ? AND consumed_at IS NULL AND expires_at > ? RETURNING project_id',
            [Carbon::now(), hash('sha256', $state), $user->getKey(), $key->value, Carbon::now()],
        );
        if ($consumed === null) {
            Log::warning('repository_provider.state_rejected', ['provider' => $key->value, 'user_id' => $user->getKey()]);

            throw new ApiException(ErrorCode::ProviderStateInvalid, null, ['provider' => $key->value]);
        }

        try {
            $tokens = $provider->exchangeCode($code);
            $identity = $provider->identity($tokens->accessToken);
        } catch (ProviderException $e) {
            // The token endpoint answers 400 (invalid_grant) for a refused,
            // expired or already used code: the user must authorize again.
            if ($e->status === 400) {
                throw new ApiException(ErrorCode::ProviderAuthRequired, null, ['provider' => $key->value]);
            }
            throw ProviderErrors::api($e, $key, ErrorCode::ProviderAuthRequired, 'complete_authorization');
        }

        $taken = RepositoryProviderAccount::query()->where('provider', $key->value)
            ->where('provider_user_id', $identity->id)->where('user_id', '!=', $user->getKey())->exists();
        if ($taken) {
            $this->refuse($provider, $tokens, $user);
        }
        try {
            $this->store($user, $key, $identity, $tokens);
        } catch (UniqueConstraintViolationException) {
            // Linked to another user concurrently.
            $this->refuse($provider, $tokens, $user);
        }

        Log::info('repository_provider.authorized', ['provider' => $key->value, 'user_id' => $user->getKey(), 'provider_user_id' => $identity->id]);

        return $consumed->project_id;
    }

    private function store(User $user, RepositoryProviderKey $key, ProviderIdentity $identity, ProviderTokens $tokens): void
    {
        $this->db->transaction(function () use ($user, $key, $identity, $tokens): void {
            $account = RepositoryProviderAccount::query()->where('user_id', $user->getKey())->where('provider', $key->value)->lockForUpdate()->first()
                ?? new RepositoryProviderAccount;
            $account->forceFill([
                'user_id' => $user->getKey(),
                'provider' => $key,
                'provider_user_id' => $identity->id,
                'username' => $identity->username,
                'access_token' => $tokens->accessToken,
                'access_token_expires_at' => $tokens->accessTokenExpiresAt,
                'refresh_token' => $tokens->refreshToken,
            ])->save();
        });
    }

    /**
     * @throws ApiException PROVIDER_ACCOUNT_IN_USE, always
     */
    private function refuse(RepositoryProvider $provider, ProviderTokens $tokens, User $user): never
    {
        try {
            $provider->revoke($tokens->accessToken);
        } catch (Throwable) {
            // Best effort: the tokens are never stored either way.
        }
        Log::warning('repository_provider.identity_in_use', ['provider' => $provider->key()->value, 'user_id' => $user->getKey()]);

        throw new ApiException(ErrorCode::ProviderAccountInUse, null, ['provider' => $provider->key()->value]);
    }
}
