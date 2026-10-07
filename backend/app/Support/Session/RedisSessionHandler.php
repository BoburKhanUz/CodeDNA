<?php

declare(strict_types=1);

namespace App\Support\Session;

use Illuminate\Cache\RedisStore;
use Illuminate\Session\CacheBasedSessionHandler;

/**
 * The Redis session handler, made safe for concurrent requests (Phase 22).
 *
 * Every request loads its session when it starts and saves it when it ends.
 * A browser page sends several requests at once, so a request still running
 * while the user signs out used to save the destroyed session again, with
 * the login in it: the user was still signed in. A session that existed
 * when this request loaded it is therefore only ever overwritten, never
 * re-created (Redis SET ... XX, atomic). New sessions, including the fresh
 * ID issued at login, are written as before.
 */
final class RedisSessionHandler extends CacheBasedSessionHandler
{
    /** @var array<string, true> session IDs that held data when this request read them */
    private array $loaded = [];

    public function read($sessionId): string
    {
        $data = parent::read($sessionId);
        if ($data !== '') {
            $this->loaded[$sessionId] = true;
        }

        return $data;
    }

    public function write($sessionId, $data): bool
    {
        $store = $this->cache->getStore();
        if (! isset($this->loaded[$sessionId]) || ! $store instanceof RedisStore) {
            return parent::write($sessionId, $data);
        }
        // The same encoding RedisStore::put() uses, so reads are unchanged.
        $value = (fn (mixed $value): mixed => $this->serialize($value))->call($store, $data);
        // Only if the key still exists: a session destroyed meanwhile (logout,
        // password change) stays destroyed. Skipping the write is not an error.
        $store->connection()->set($store->getPrefix().$sessionId, $value, 'EX', max(1, (int) $this->minutes * 60), 'XX');

        return true;
    }

    public function destroy($sessionId): bool
    {
        unset($this->loaded[$sessionId]);

        return parent::destroy($sessionId);
    }
}
