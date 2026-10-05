<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard against destructive tests running on a non-test database. This
     * runs before any testing trait (e.g. RefreshDatabase) touches the schema.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against database \"{$database}\": test databases must end in \"_test\"."
            );
        }

        return $app;
    }

    /**
     * Use Redis-backed sessions (as in production) for tests that must prove
     * a session survives between requests.
     */
    protected function useRedisSessions(): void
    {
        config(['session.driver' => 'redis']);
        $this->forgetSessionState();
    }

    /**
     * Drop all in-memory session and guard state, so the next request can
     * only be authenticated by what its session cookie points to in Redis.
     */
    protected function forgetSessionState(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();
    }

    /**
     * Headers of a first-party browser request: Sanctum treats requests whose
     * Origin is a stateful domain as session-authenticated SPA requests.
     *
     * @return $this
     */
    protected function fromBrowser(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost',
            'Accept' => 'application/json',
        ]);
    }
}
