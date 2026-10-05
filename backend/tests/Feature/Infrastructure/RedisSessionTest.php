<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Cache\RedisStore;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

final class RedisSessionTest extends TestCase
{
    public function test_sessions_persist_in_redis_across_requests(): void
    {
        $session = $this->app['session']->driver('redis');
        $session->start();
        $session->put('codedna.test', 'persisted');
        $session->save();
        $id = $session->getId();

        // The session data is stored in Redis under the session ID...
        $handler = $session->getHandler();
        $this->assertInstanceOf(CacheBasedSessionHandler::class, $handler);
        $this->assertInstanceOf(RedisStore::class, $handler->getCache()->getStore());
        $this->assertTrue($handler->getCache()->has($id));

        // ...and a new store (as on the next request) reads it back.
        $next = new Store($session->getName(), $session->getHandler(), $id, (string) config('session.serialization'));
        $next->start();
        $this->assertSame('persisted', $next->get('codedna.test'));

        $handler->destroy($id);
        $this->assertFalse($handler->getCache()->has($id));
    }
}
