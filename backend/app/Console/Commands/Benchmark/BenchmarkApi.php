<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * In-process API benchmark (Phase 26, docs/performance/benchmarking.md#api).
 *
 * Sends real requests through the HTTP kernel (routing, middleware,
 * authorization, resources) as benchmark users against the benchmark
 * database, and reports per endpoint: status, latency percentiles, query
 * count, query time, response size and peak memory. Subjects rotate across
 * users and projects, so caches are not artificially warm; the outliers
 * (long history, large organization) are measured separately.
 *
 * What it leaves out (measured by the HTTP load test instead): Nginx,
 * PHP-FPM, cookie decryption and the Redis session read. The per-user API
 * rate limit is lifted for this process only (it would otherwise turn
 * repeated measurements into 429s); it is never changed for the application.
 */
final class BenchmarkApi extends Command
{
    protected $signature = 'benchmark:api
        {--iterations=30 : Measured requests per endpoint (after 3 warm-up requests)}
        {--filter= : Only endpoints whose name contains this text}
        {--json= : Also write the results as JSON to this path}
        {--show-queries : Print the SQL of the last request of each endpoint}';

    protected $description = 'Measure API latency, query counts and response sizes on the benchmark database';

    private int $queries = 0;

    private float $queryMs = 0.0;

    /** @var list<string> */
    private array $log = [];

    public function handle(BenchmarkGuard $guard, Kernel $kernel): int
    {
        $guard->assertBenchmarkDatabase();
        RateLimiter::for('api', static fn (): Limit => Limit::none());
        DB::listen(function (QueryExecuted $query): void {
            $this->queries++;
            $this->queryMs += $query->time;
            $this->log[] = sprintf('%6.2f ms  %s', $query->time, $query->sql);
        });

        $iterations = max(1, (int) $this->option('iterations'));
        $filter = (string) $this->option('filter');
        $results = [];
        foreach ((new BenchmarkEndpoints)->all() as $name => $endpoint) {
            if ($filter !== '' && ! str_contains($name, $filter)) {
                continue;
            }
            $samples = [];
            for ($i = -3; $i < $iterations; $i++) {
                [$user, $uri] = $endpoint(max(0, $i));
                $sample = $this->request($kernel, $user, $uri);
                if ($i >= 0) {
                    $samples[] = $sample;
                }
            }
            $results[$name] = $this->summarize($samples);
            if ($this->option('show-queries')) {
                foreach ($this->log as $line) {
                    $this->line('    '.$line);
                }
            }
            $r = $results[$name];
            $this->line(sprintf('%-34s %3s p50 %6.1f p95 %6.1f p99 %6.1f ms  q %3d (%5.1f ms)  %7s B  %5.1f MB',
                $name, $r['status'], $r['p50_ms'], $r['p95_ms'], $r['p99_ms'], $r['queries_max'], $r['query_ms_p50'], number_format($r['bytes_max']), $r['memory_peak_mb']));
        }

        $json = (string) $this->option('json');
        if ($json !== '') {
            file_put_contents($json, json_encode(['iterations' => $iterations, 'endpoints' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
        $failed = array_filter($results, static fn (array $r): bool => $r['status'] !== '200');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{status: int, ms: float, queries: int, query_ms: float, bytes: int, memory: int} */
    private function request(Kernel $kernel, User $user, string $uri): array
    {
        app()->forgetScopedInstances();
        // Sanctum's stateful path resolves the session user from the web
        // guard; guards cache their user, so start from fresh ones.
        auth()->forgetGuards();
        auth()->guard('web')->setUser($user);
        $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.255.0.1']);
        $this->queries = 0;
        $this->queryMs = 0.0;
        $this->log = [];
        memory_reset_peak_usage();
        $started = hrtime(true);
        $response = $kernel->handle($request);
        $ms = (hrtime(true) - $started) / 1e6;
        $kernel->terminate($request, $response);

        return [
            'status' => $response->getStatusCode(),
            'ms' => $ms,
            'queries' => $this->queries,
            'query_ms' => $this->queryMs,
            'bytes' => strlen((string) $response->getContent()),
            'memory' => memory_get_peak_usage(),
        ];
    }

    /**
     * @param  list<array{status: int, ms: float, queries: int, query_ms: float, bytes: int, memory: int}>  $samples
     * @return array<string, mixed>
     */
    private function summarize(array $samples): array
    {
        $ms = array_column($samples, 'ms');
        sort($ms);
        $queryMs = array_column($samples, 'query_ms');
        sort($queryMs);
        $statuses = array_unique(array_column($samples, 'status'));
        $pick = static fn (array $sorted, float $p): float => round($sorted[(int) min(count($sorted) - 1, max(0, ceil($p * count($sorted)) - 1))], 1);

        return [
            'status' => implode('/', $statuses),
            'p50_ms' => $pick($ms, 0.50),
            'p95_ms' => $pick($ms, 0.95),
            'p99_ms' => $pick($ms, 0.99),
            'queries_min' => min(array_column($samples, 'queries')),
            'queries_max' => max(array_column($samples, 'queries')),
            'query_ms_p50' => $pick($queryMs, 0.50),
            'bytes_max' => max(array_column($samples, 'bytes')),
            'memory_peak_mb' => round(max(array_column($samples, 'memory')) / 1048576, 1),
        ];
    }
}
