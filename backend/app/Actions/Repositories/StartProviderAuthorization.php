<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\Billing\Feature;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Models\Project;
use App\Models\RepositoryProviderOAuthState;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Starts a GitLab or Bitbucket Cloud authorization (Phase 28,
 * docs/integrations/oauth-setup.md#the-flow). The state is 32 random bytes
 * (base64url); only its SHA-256 is stored, bound to this user and this
 * provider, valid for state_ttl_seconds and usable once. The returned URL is
 * the provider's configured origin only.
 */
final readonly class StartProviderAuthorization
{
    public function __construct(private RepositoryProviders $providers, private Entitlements $entitlements) {}

    /**
     * @return array{authorize_url: string, expires_at: string}
     */
    public function handle(User $user, RepositoryProviderKey $key, ?Project $returnTo = null): array
    {
        $provider = $this->providers->get($key);
        // Billing (Phase 23): repository integrations are a plan feature (shared with GitHub).
        $this->entitlements->require($user, Feature::GitHubIntegration);

        $now = Carbon::now();
        // Housekeeping: this user's used or expired states are of no further use.
        RepositoryProviderOAuthState::query()->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->whereNotNull('consumed_at')->orWhere('expires_at', '<=', $now))
            ->delete();

        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $now->copy()->addSeconds(max(60, min(3600, $provider->settings()->stateTtlSeconds)));
        $record = new RepositoryProviderOAuthState;
        $record->forceFill([
            'user_id' => $user->getKey(),
            'provider' => $key,
            'project_id' => $returnTo?->getKey(),
            'state_hash' => hash('sha256', $state),
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ])->save();

        Log::info('repository_provider.authorization_started', ['provider' => $key->value, 'user_id' => $user->getKey(), 'project_id' => $returnTo?->getKey()]);

        return ['authorize_url' => $provider->authorizationUrl($state), 'expires_at' => $expiresAt->toIso8601ZuluString()];
    }
}
