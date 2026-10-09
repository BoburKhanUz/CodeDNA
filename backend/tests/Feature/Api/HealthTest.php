<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_reports_ok_when_dependencies_are_reachable(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['data' => [
                'status' => 'ok',
                'service' => 'codedna-api',
                'version' => config('codedna.version'),
                'api_version' => 'v1',
                'checks' => ['database' => 'ok', 'redis' => 'ok'],
            ]]);
    }

    public function test_does_not_expose_connection_details(): void
    {
        $body = $this->getJson('/api/v1/health')->getContent() ?: '';

        foreach ([
            (string) config('database.connections.pgsql.password'),
            config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port'),
            config('database.redis.default.host').':'.config('database.redis.default.port'),
            base_path(),
        ] as $sensitive) {
            if ($sensitive !== '') {
                $this->assertStringNotContainsString($sensitive, $body);
            }
        }
    }

    public function test_returns_503_when_redis_is_unreachable(): void
    {
        config(['database.redis.default.port' => 1, 'database.redis.default.max_retries' => 0]);
        Redis::purge('default');

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'fail')
            ->assertJsonPath('data.checks', ['database' => 'ok', 'redis' => 'fail']);
    }

    /**
     * Phase 30: in production the rate limiter's counters live in Redis too
     * (CACHE_STORE=redis). With Redis down the health check must still
     * answer its own 503, not an INTERNAL_ERROR from the throttle.
     */
    public function test_returns_503_not_500_when_redis_is_down_and_the_cache_lives_in_redis(): void
    {
        config([
            'cache.default' => 'redis',
            'database.redis.default.port' => 1, 'database.redis.default.max_retries' => 0,
            'database.redis.cache.port' => 1, 'database.redis.cache.max_retries' => 0,
        ]);
        Redis::purge('default');
        Redis::purge('cache');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance(RateLimiter::class);

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.checks', ['database' => 'ok', 'redis' => 'fail']);
    }

    public function test_returns_503_when_the_database_is_unreachable(): void
    {
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'fail')
            ->assertJsonPath('data.checks', ['database' => 'fail', 'redis' => 'ok']);
    }
}
