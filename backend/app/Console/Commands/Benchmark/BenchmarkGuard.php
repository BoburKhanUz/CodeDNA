<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Every benchmark command writes or reads synthetic data in bulk. It runs
 * only outside production, and only against a dedicated database whose name
 * ends in "_benchmark" (make benchmark-seed creates codedna_benchmark), so a
 * misdirected command can never touch real data.
 */
final readonly class BenchmarkGuard
{
    public function __construct(private Repository $config, private ConnectionInterface $db) {}

    public function assertBenchmarkDatabase(): void
    {
        if (! in_array($this->config->get('app.env'), ['local', 'testing'], true)) {
            throw new RuntimeException('Benchmark commands run only in local or testing environments.');
        }
        $database = (string) $this->db->getDatabaseName();
        if (! str_ends_with($database, '_benchmark')) {
            throw new RuntimeException("Refusing to run against database \"{$database}\": benchmark commands need a dedicated *_benchmark database (DB_DATABASE=codedna_benchmark).");
        }
    }
}
