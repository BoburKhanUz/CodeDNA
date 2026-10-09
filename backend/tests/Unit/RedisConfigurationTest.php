<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * REDIS_SCHEME (Phase 27). docker-compose.prod.yml passes it as an empty
 * string when unset; Laravel treats any set scheme as a host prefix, so an
 * empty string must become null or the host resolves to "" (found by the
 * production smoke test).
 */
final class RedisConfigurationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function redisWithScheme(?string $scheme): array
    {
        $previous = [$_ENV['REDIS_SCHEME'] ?? null, $_SERVER['REDIS_SCHEME'] ?? null];
        if ($scheme === null) {
            unset($_ENV['REDIS_SCHEME'], $_SERVER['REDIS_SCHEME']);
        } else {
            $_ENV['REDIS_SCHEME'] = $_SERVER['REDIS_SCHEME'] = $scheme;
        }
        try {
            return (require base_path('config/database.php'))['redis'];
        } finally {
            [$_ENV['REDIS_SCHEME'], $_SERVER['REDIS_SCHEME']] = $previous;
            if ($previous[0] === null) {
                unset($_ENV['REDIS_SCHEME']);
            }
            if ($previous[1] === null) {
                unset($_SERVER['REDIS_SCHEME']);
            }
        }
    }

    public function test_an_empty_or_unset_scheme_means_plain_tcp(): void
    {
        foreach (['', null] as $scheme) {
            $redis = $this->redisWithScheme($scheme);
            $this->assertNull($redis['default']['scheme']);
            $this->assertNull($redis['cache']['scheme']);
        }
    }

    public function test_tls_is_passed_through(): void
    {
        $redis = $this->redisWithScheme('tls');
        $this->assertSame(['tls', 'tls'], [$redis['default']['scheme'], $redis['cache']['scheme']]);
    }
}
