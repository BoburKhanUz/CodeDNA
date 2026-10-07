<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Enums\Billing\Feature;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\GitHubOAuthState;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\GitHub\GitHubSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Starts a GitHub authorization (docs/architecture/github-integration-v1.md#authorization).
 *
 * The state is 32 random bytes (base64url). Only its SHA-256 is stored,
 * bound to this user, valid for state_ttl_seconds and usable once. The
 * returned URLs point at the configured GitHub web origin only: one to
 * authorize the App, one to install it (GitHub then asks for authorization
 * too). Neither carries a secret beyond the state itself.
 */
final readonly class StartGitHubAuthorization
{
    public function __construct(private GitHubSettings $settings, private Entitlements $entitlements) {}

    /**
     * @return array{authorize_url: string, install_url: string, expires_at: string}
     */
    public function handle(User $user, ?Project $returnTo = null): array
    {
        if (! $this->settings->configured()) {
            throw new ApiException(ErrorCode::GitHubNotConfigured);
        }
        // Billing (Phase 23): the user's plan must include GitHub integration.
        $this->entitlements->require($user, Feature::GitHubIntegration);

        $now = Carbon::now();
        // Housekeeping: this user's used or expired states are of no further use.
        GitHubOAuthState::query()->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->whereNotNull('consumed_at')->orWhere('expires_at', '<=', $now))
            ->delete();

        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $now->copy()->addSeconds(max(60, min(3600, $this->settings->stateTtlSeconds)));
        $record = new GitHubOAuthState;
        $record->forceFill([
            'user_id' => $user->getKey(),
            'project_id' => $returnTo?->getKey(),
            'state_hash' => hash('sha256', $state),
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ])->save();

        Log::info('github.authorization_started', ['user_id' => $user->getKey(), 'project_id' => $returnTo?->getKey()]);

        return [
            'authorize_url' => $this->settings->webUrl.'/login/oauth/authorize?'.http_build_query([
                'client_id' => $this->settings->clientId,
                'redirect_uri' => $this->settings->callbackUrl,
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986),
            'install_url' => $this->settings->webUrl.'/apps/'.rawurlencode($this->settings->appSlug).'/installations/new?'.http_build_query([
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986),
            'expires_at' => $expiresAt->toIso8601ZuluString(),
        ];
    }
}
