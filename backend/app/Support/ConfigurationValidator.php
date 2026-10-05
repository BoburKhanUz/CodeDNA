<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Validates configuration that CodeDNA depends on, so misconfiguration fails
 * at boot with a clear message instead of surfacing later as odd behavior.
 */
final class ConfigurationValidator
{
    /**
     * @return list<string> problems found (empty when the configuration is valid)
     */
    public function problems(Repository $config, string $environment): array
    {
        $problems = [];

        if (! is_string($config->get('app.key')) || $config->get('app.key') === '') {
            $problems[] = 'APP_KEY is not set.';
        }

        if ($config->get('database.default') !== 'pgsql') {
            $problems[] = 'DB_CONNECTION must be "pgsql" (CodeDNA requires PostgreSQL).';
        }

        // The test suite swaps in in-memory drivers; every other environment
        // must use the Redis-backed infrastructure (docs/architecture/backend.md).
        if ($environment !== 'testing') {
            foreach (['session.driver' => 'SESSION_DRIVER', 'cache.default' => 'CACHE_STORE', 'queue.default' => 'QUEUE_CONNECTION'] as $key => $variable) {
                if ($config->get($key) !== 'redis') {
                    $problems[] = "{$variable} must be \"redis\".";
                }
            }
        }

        if ($environment === 'production') {
            if ($config->get('app.debug') === true) {
                $problems[] = 'APP_DEBUG must be false in production.';
            }

            if (! str_starts_with((string) $config->get('app.url'), 'https://')) {
                $problems[] = 'APP_URL must use https:// in production.';
            }

            if ($config->get('session.secure') !== true) {
                $problems[] = 'SESSION_SECURE_COOKIE must be true in production.';
            }

            if (array_filter((array) $config->get('sanctum.stateful')) === []) {
                $problems[] = 'SANCTUM_STATEFUL_DOMAINS must list the production frontend domain.';
            }
        }

        return $problems;
    }
}
