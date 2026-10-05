<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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
