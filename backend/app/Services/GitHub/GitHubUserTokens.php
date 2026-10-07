<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Illuminate\Support\Carbon;

/**
 * A GitHub App user access token set, as returned by GitHub's OAuth token
 * endpoint. Short-lived and held in memory only, until it is encrypted
 * into github_accounts.
 */
final readonly class GitHubUserTokens
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        public ?Carbon $accessTokenExpiresAt,
        #[\SensitiveParameter] public ?string $refreshToken,
        public ?Carbon $refreshTokenExpiresAt,
    ) {}

    /**
     * @throws GitHubException
     */
    public static function fromApi(mixed $data): self
    {
        if (! is_array($data) || ! is_string($data['access_token'] ?? null) || $data['access_token'] === '' || strlen($data['access_token']) > 1024) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }
        $refresh = $data['refresh_token'] ?? null;
        $refresh = is_string($refresh) && $refresh !== '' && strlen($refresh) <= 1024 ? $refresh : null;
        $expiresIn = $data['expires_in'] ?? null;
        $refreshExpiresIn = $data['refresh_token_expires_in'] ?? null;

        return new self(
            accessToken: $data['access_token'],
            accessTokenExpiresAt: is_int($expiresIn) && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
            refreshToken: $refresh,
            refreshTokenExpiresAt: $refresh !== null && is_int($refreshExpiresIn) && $refreshExpiresIn > 0 ? Carbon::now()->addSeconds($refreshExpiresIn) : null,
        );
    }

    /** Never print a token, even by accident (var_dump, dd, exceptions with arguments). */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'refreshToken' => $this->refreshToken === null ? null : '[redacted]'];
    }
}
