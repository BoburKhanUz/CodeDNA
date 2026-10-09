<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * php artisan codedna:preflight (Phase 27): read-only checks before an
 * installation starts, reporting failures by dependency, never by value.
 */
final class PreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_healthy_installation_passes(): void
    {
        $this->artisan('codedna:preflight')
            ->expectsOutputToContain('Preflight passed.')
            ->assertSuccessful();
    }

    public function test_an_unreachable_dependency_fails_without_revealing_configuration(): void
    {
        // An endpoint that cannot be resolved: the client's error message
        // names the host, and none of it may reach the output.
        config([
            'database.redis.default.host' => 'preflight-unreachable-redis-27',
            'filesystems.disks.sources.endpoint' => 'http://preflight-unreachable-host-27:9000',
            'filesystems.disks.sources.bucket' => 'preflight-missing-bucket-27',
            'filesystems.disks.sources.secret' => 'preflight-secret-value-27',
        ]);
        Storage::forgetDisk('sources');
        Redis::purge('default');

        $this->artisan('codedna:preflight')
            ->expectsOutputToContain('object storage')
            ->expectsOutputToContain('Preflight failed.')
            ->doesntExpectOutputToContain('preflight-unreachable-host-27')
            ->doesntExpectOutputToContain('preflight-unreachable-redis-27')
            ->doesntExpectOutputToContain('preflight-missing-bucket-27')
            ->doesntExpectOutputToContain('preflight-secret-value-27')
            ->assertFailed();
    }

    public function test_nothing_is_written(): void
    {
        $before = Storage::disk('sources')->allFiles();
        $this->artisan('codedna:preflight')->assertSuccessful();
        $this->assertSame($before, Storage::disk('sources')->allFiles());
    }
}
