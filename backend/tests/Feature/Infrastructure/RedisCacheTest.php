<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RedisCacheTest extends TestCase
{
    public function test_writes_and_reads_values(): void
    {
        $cache = Cache::store('redis');
        $key = 'codedna-test:'.Str::random(12);

        $this->assertTrue($cache->put($key, ['score' => 0.9125], 60));
        $this->assertSame(['score' => 0.9125], $cache->get($key));

        $store = $cache->getStore();
        $this->assertInstanceOf(RedisStore::class, $store);
        $ttl = $store->connection()->ttl($store->getPrefix().$key);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);

        $cache->forget($key);
        $this->assertNull($cache->get($key));
    }

    public function test_values_expire(): void
    {
        $cache = Cache::store('redis');
        $key = 'codedna-test:'.Str::random(12);

        $cache->put($key, 'short-lived', 1);
        $this->assertSame('short-lived', $cache->get($key));

        usleep(1_500_000);

        $this->assertNull($cache->get($key));
    }
}
