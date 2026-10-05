<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Readiness checks for the API's hard dependencies.
 *
 * Results are reduced to "ok"/"fail": connection errors can contain hosts,
 * ports or usernames, so details are logged, never returned.
 */
final readonly class SystemHealth
{
    public function __construct(
        private DatabaseManager $database,
        private RedisFactory $redis,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{database: bool, redis: bool}
     */
    public function check(): array
    {
        return [
            'database' => $this->probe('database', function (): void {
                $this->database->connection()->select('select 1');
            }),
            'redis' => $this->probe('redis', function (): void {
                $this->redis->connection()->ping();
            }),
        ];
    }

    /**
     * @param  callable(): void  $check
     */
    private function probe(string $name, callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Health check failed', ['check' => $name, 'exception' => $e::class]);

            return false;
        }
    }
}
