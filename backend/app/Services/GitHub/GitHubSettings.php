<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Illuminate\Contracts\Config\Repository;

/**
 * The GitHub App configuration (config/codedna.php "github"). Secrets are
 * read from the environment only; nothing here is ever logged or returned
 * by the API. The integration is configured only when every credential is set.
 */
final readonly class GitHubSettings
{
    /**
     * @param  list<string>  $archiveOrigins
     */
    public function __construct(
        public string $appId,
        public string $appSlug,
        public string $clientId,
        public string $clientSecret,
        public string $privateKey,
        public string $apiUrl,
        public string $webUrl,
        public array $archiveOrigins,
        public string $callbackUrl,
        public int $stateTtlSeconds,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $downloadTimeoutSeconds,
        public int $maxResponseBytes,
        public int $retryDelayMs,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        /** @var array<string, mixed> $github */
        $github = (array) $config->get('codedna.github', []);

        return new self(
            appId: (string) ($github['app_id'] ?? ''),
            appSlug: (string) ($github['app_slug'] ?? ''),
            clientId: (string) ($github['client_id'] ?? ''),
            clientSecret: (string) ($github['client_secret'] ?? ''),
            privateKey: self::privateKey($github),
            apiUrl: rtrim((string) ($github['api_url'] ?? ''), '/'),
            webUrl: rtrim((string) ($github['web_url'] ?? ''), '/'),
            archiveOrigins: array_values(array_map(static fn ($o): string => rtrim((string) $o, '/'), (array) ($github['archive_origins'] ?? []))),
            callbackUrl: (string) ($github['callback_url'] ?? ''),
            stateTtlSeconds: (int) ($github['state_ttl_seconds'] ?? 600),
            connectTimeoutSeconds: (int) ($github['connect_timeout_seconds'] ?? 5),
            timeoutSeconds: (int) ($github['timeout_seconds'] ?? 15),
            downloadTimeoutSeconds: (int) ($github['download_timeout_seconds'] ?? 120),
            maxResponseBytes: (int) ($github['max_response_bytes'] ?? 4 * 1024 * 1024),
            retryDelayMs: (int) ($github['retry_delay_ms'] ?? 250),
        );
    }

    public function configured(): bool
    {
        return $this->appId !== '' && $this->appSlug !== '' && $this->clientId !== ''
            && $this->clientSecret !== '' && $this->privateKey !== '';
    }

    /**
     * @param  array<string, mixed>  $github
     */
    private static function privateKey(array $github): string
    {
        $inline = (string) ($github['private_key'] ?? '');
        if ($inline !== '') {
            return str_replace('\n', "\n", $inline);
        }
        $path = (string) ($github['private_key_path'] ?? '');
        if ($path !== '' && is_file($path) && is_readable($path)) {
            return (string) file_get_contents($path);
        }

        return '';
    }
}
