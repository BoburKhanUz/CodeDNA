<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Models\RepositoryProviderAccount;
use App\Models\User;
use App\Services\Repositories\ProviderException;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Disconnects the user's GitLab or Bitbucket account (Phase 28): revokes the
 * authorization at the provider where it supports revocation (GitLab), then
 * deletes CodeDNA's copy of the tokens — always, even when the remote
 * revocation fails or the provider is not configured any more. Connections
 * and snapshots are kept; future imports need a new authorization.
 *
 * @return string the remote outcome: REVOKED, NOT_SUPPORTED, FAILED or NOT_LINKED
 */
final readonly class UnlinkProviderAccount
{
    public function __construct(private RepositoryProviders $providers) {}

    public function handle(User $user, RepositoryProviderKey $key): string
    {
        $account = RepositoryProviderAccount::query()->where('user_id', $user->getKey())->where('provider', $key->value)->first();
        if ($account === null) {
            return 'NOT_LINKED';
        }

        $outcome = 'FAILED';
        try {
            $outcome = $this->providers->get($key)->revoke($account->access_token) ? 'REVOKED' : 'NOT_SUPPORTED';
        } catch (ProviderException $e) {
            Log::warning('repository_provider.revoke_failed', ['provider' => $key->value, 'user_id' => $user->getKey(), 'error' => $e->error->value, 'status' => $e->status]);
        } catch (Throwable $e) {
            Log::warning('repository_provider.revoke_failed', ['provider' => $key->value, 'user_id' => $user->getKey(), 'exception' => $e::class]);
        } finally {
            RepositoryProviderAccount::query()->whereKey($account->id)->delete();
        }

        Log::info('repository_provider.unlinked', ['provider' => $key->value, 'user_id' => $user->getKey(), 'revocation' => $outcome]);

        return $outcome;
    }
}
