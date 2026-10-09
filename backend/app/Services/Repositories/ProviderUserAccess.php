<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\RepositoryProviderAccount;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * A usable access token for a user's provider account (Phase 28): the stored
 * one, or a refreshed one when it expires within a minute. The refresh runs
 * under the account's row lock (GitLab rotates refresh tokens: two concurrent
 * refreshes would invalidate each other) and is bounded by the provider HTTP
 * timeouts. Without an account, or when the provider refuses the refresh,
 * the user must authorize again (PROVIDER_AUTH_REQUIRED).
 */
final readonly class ProviderUserAccess
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @throws ApiException PROVIDER_AUTH_REQUIRED
     * @throws ProviderException when the provider is unreachable during a refresh
     */
    public function token(RepositoryProvider $provider, string $userId): string
    {
        $account = $this->account($provider->key(), $userId, lock: false);
        if (! self::expiring($account)) {
            return $account->access_token;
        }

        return $this->db->transaction(function () use ($provider, $userId): string {
            $locked = $this->account($provider->key(), $userId, lock: true);
            if (! self::expiring($locked)) {
                return $locked->access_token;
            }
            $refresh = $locked->refresh_token ?? throw self::authRequired($provider->key());
            try {
                $tokens = $provider->refresh($refresh);
            } catch (ProviderException $e) {
                if (in_array($e->error, [ProviderError::Unauthorized, ProviderError::Forbidden, ProviderError::InvalidResponse], true) || $e->status === 400) {
                    throw self::authRequired($provider->key());
                }
                throw $e;
            }
            $locked->forceFill([
                'access_token' => $tokens->accessToken,
                'access_token_expires_at' => $tokens->accessTokenExpiresAt,
                'refresh_token' => $tokens->refreshToken ?? $refresh,
            ])->save();

            return $tokens->accessToken;
        });
    }

    private function account(RepositoryProviderKey $key, string $userId, bool $lock): RepositoryProviderAccount
    {
        $query = RepositoryProviderAccount::query()->where('user_id', $userId)->where('provider', $key->value);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw self::authRequired($key);
    }

    private static function expiring(RepositoryProviderAccount $account): bool
    {
        return $account->access_token_expires_at !== null && $account->access_token_expires_at->lte(Carbon::now()->addMinute());
    }

    private static function authRequired(RepositoryProviderKey $key): ApiException
    {
        return new ApiException(ErrorCode::ProviderAuthRequired, null, ['provider' => $key->value]);
    }
}
