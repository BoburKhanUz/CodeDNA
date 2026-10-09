<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;

/**
 * What can be said about local AI without generating anything (Phase 29,
 * docs/operations/local-ai.md#health): enabled, provider kind, model, and a
 * cached connectivity / model availability check. Four levels are kept
 * apart and only the first two are checked here:
 *
 * 1. the runtime answers (reachable);
 * 2. the configured model is available;
 * 3. a minimal generation succeeds (php artisan ai:smoke, on demand only);
 * 4. a full assessment or insight succeeds (its own status).
 */
final readonly class AiStatus
{
    public function __construct(private ModelClient $client, private Cache $cache, private Repository $config) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('codedna.ai.enabled');
    }

    /** "local" (a service on the private network), "remote" or "fake". */
    public function endpointKind(): string
    {
        if ($this->client->name() === 'fake') {
            return 'fake';
        }
        $host = (string) parse_url((string) $this->config->get('codedna.ai.base_url'), PHP_URL_HOST);

        $internal = ($host !== '' && ! str_contains($host, '.') && ! str_contains($host, ':')) || in_array($host, ['localhost', 'host.docker.internal'], true);

        return $internal ? 'local' : 'remote';
    }

    /**
     * The cached health check (AI_HEALTH_CACHE_SECONDS); $fresh bypasses it.
     */
    public function health(bool $fresh = false): ModelHealth
    {
        $key = 'codedna:ai:health:'.hash('sha256', $this->client->name().'|'.$this->client->model().'|'.$this->config->get('codedna.ai.base_url'));
        if (! $fresh) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return new ModelHealth((bool) $cached['reachable'], $cached['model_available'], $cached['runtime_version'], $cached['error']);
            }
        }
        $health = $this->client->health();
        $this->cache->put($key, $health->toArray(), max(1, (int) $this->config->get('codedna.ai.health_cache_seconds')));

        return $health;
    }

    /**
     * Whether a request now has a chance: enabled, reachable and the model
     * available (unknown availability counts as available).
     */
    public function available(): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $health = $this->health();

        return $health->reachable && $health->modelAvailable !== false;
    }
}
