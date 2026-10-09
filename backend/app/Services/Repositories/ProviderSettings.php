<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use Illuminate\Contracts\Config\Repository;

/**
 * One OAuth repository provider's configuration (Phase 28). Every URL a
 * request is ever sent to is derived from these values: the API origin, the
 * OAuth (web) origin and the archive origins. Timeouts, response limits and
 * the queue are shared with the GitHub integration (codedna.github).
 */
final readonly class ProviderSettings
{
    /**
     * @param  list<string>  $archiveOrigins
     */
    public function __construct(
        public RepositoryProviderKey $key,
        public string $clientId,
        #[\SensitiveParameter] public string $clientSecret,
        public string $apiUrl,
        public string $webUrl,
        public string $callbackUrl,
        public array $archiveOrigins,
        public string $scopes,
        public int $stateTtlSeconds,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $downloadTimeoutSeconds,
        public int $maxResponseBytes,
        public int $retryDelayMs,
    ) {}

    public static function fromConfig(Repository $config, RepositoryProviderKey $key): self
    {
        $provider = (array) $config->get('codedna.repository_providers.'.$key->value, []);
        $github = (array) $config->get('codedna.github', []);
        [$api, $web, $origins] = match ($key) {
            // One administrator-set origin for everything (GitLab.com or self-managed).
            RepositoryProviderKey::GitLab => [
                (string) ($provider['base_url'] ?? ''),
                (string) ($provider['base_url'] ?? ''),
                [self::origin((string) ($provider['base_url'] ?? ''))],
            ],
            RepositoryProviderKey::Bitbucket => [
                (string) ($provider['api_url'] ?? ''),
                (string) ($provider['web_url'] ?? ''),
                array_values(array_map('strval', (array) ($provider['archive_origins'] ?? []))),
            ],
        };

        return new self(
            key: $key,
            clientId: (string) ($provider['client_id'] ?? ''),
            clientSecret: (string) ($provider['client_secret'] ?? ''),
            apiUrl: $api,
            webUrl: $web,
            callbackUrl: (string) ($provider['callback_url'] ?? ''),
            archiveOrigins: array_values(array_filter($origins, static fn (string $o): bool => $o !== '')),
            scopes: (string) ($provider['scopes'] ?? ''),
            stateTtlSeconds: (int) ($github['state_ttl_seconds'] ?? 600),
            connectTimeoutSeconds: (int) ($github['connect_timeout_seconds'] ?? 5),
            timeoutSeconds: (int) ($github['timeout_seconds'] ?? 15),
            downloadTimeoutSeconds: (int) ($github['download_timeout_seconds'] ?? 120),
            maxResponseBytes: (int) ($github['max_response_bytes'] ?? 4 * 1024 * 1024),
            retryDelayMs: (int) ($github['retry_delay_ms'] ?? 250),
        );
    }

    /** Off until both OAuth client credentials are set. */
    public function configured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /** scheme://host[:port] of a URL, lowercased; '' when it has none. */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
