<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use Illuminate\Support\Carbon;

/**
 * Tokens from a provider's OAuth token endpoint (Phase 28). Held in memory,
 * stored only encrypted (repository_provider_accounts), never logged.
 */
final readonly class ProviderTokens
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        public ?Carbon $accessTokenExpiresAt,
        #[\SensitiveParameter] public ?string $refreshToken,
    ) {}

    /**
     * @throws ProviderException
     */
    public static function fromApi(mixed $data): self
    {
        if (! is_array($data) || ! is_string($data['access_token'] ?? null) || $data['access_token'] === '' || strlen($data['access_token']) > 4096
            || strtolower((string) ($data['token_type'] ?? 'bearer')) !== 'bearer') {
            throw new ProviderException(ProviderError::InvalidResponse);
        }
        $refresh = $data['refresh_token'] ?? null;
        $refresh = is_string($refresh) && $refresh !== '' && strlen($refresh) <= 4096 ? $refresh : null;
        $expiresIn = $data['expires_in'] ?? null;

        return new self(
            accessToken: $data['access_token'],
            accessTokenExpiresAt: is_int($expiresIn) && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
            refreshToken: $refresh,
        );
    }

    /** Never print a token, even by accident. */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'refreshToken' => $this->refreshToken === null ? null : '[redacted]'];
    }
}
