<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use App\Actions\Analysis\StartAnalysis;
use App\Actions\Projects\CreateProject;
use App\Actions\Snapshots\StoreUploadedSource;
use App\Enums\AnalysisResultType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Queue benchmark (Phase 26, docs/performance/queue-performance.md).
 *
 * For each worker count: creates throwaway users and projects in the
 * benchmark database, uploads a generated archive through the real upload
 * action and starts one analysis each through the real StartAnalysis
 * (measuring enqueue latency), then forks that many real `queue:work`
 * worker processes until the queue is empty, against the real analyzer. Reports
 * throughput, queue wait, execution time, retries, failures, upload time
 * and memory, and the peak number of PostgreSQL connections.
 *
 * Isolation: run with DB_DATABASE=*_benchmark and a Redis database of its own
 * (make benchmark-queue uses REDIS_DB=5), so the development worker never
 * sees these jobs. Queue wait and execution come from the runs' stored
 * timestamps (whole seconds); enqueue and upload are timed in-process.
 */
final class BenchmarkQueue extends Command
{
    protected $signature = 'benchmark:queue
        {--workers=1,2,4 : Worker counts to measure}
        {--analyses=24 : Analyses per worker count}
        {--archive= : The archive to upload (scripts/benchmark/make_sources.py)}';

    protected $description = 'Measure upload, enqueue, queue wait, execution and throughput with real workers';

    public function handle(BenchmarkGuard $guard, CreateProject $createProject, StoreUploadedSource $store, StartAnalysis $start): int
    {
        $guard->assertBenchmarkDatabase();
        $archive = (string) $this->option('archive');
        if (! is_file($archive)) {
            $this->error("Missing archive {$archive}.");

            return self::INVALID;
        }
        if ((int) config('database.redis.default.database') === 0) {
            $this->error('Use a Redis database of its own (REDIS_DB), so the development worker never takes these jobs.');

            return self::INVALID;
        }
        Redis::connection()->command('flushdb');

        $rows = [];
        foreach (array_map('intval', explode(',', (string) $this->option('workers'))) as $workers) {
            $rows[] = $this->measure($workers, max(1, (int) $this->option('analyses')), $archive, $createProject, $store, $start);
        }
        $this->table(
            ['workers', 'analyses', 'enqueue p50/p95 ms', 'upload p50/p95 ms', 'upload peak MB', 'wait p50/p95 s', 'run p50/p95 s', 'throughput/min', 'retries', 'failed', 'peak DB conns'],
            $rows,
        );

        return self::SUCCESS;
    }

    /** @return list<string|int> */
    private function measure(int $workers, int $analyses, string $archive, CreateProject $createProject, StoreUploadedSource $store, StartAnalysis $start): array
    {
        $batch = 'q'.$workers.'-'.substr(md5((string) microtime(true)), 0, 6);
        $uploads = $enqueues = $memory = $runs = [];
        $password = Hash::make(SeedBenchmark::PASSWORD);
        for ($i = 1; $i <= $analyses; $i++) {
            $user = new User;
            $user->forceFill(['name' => "Queue benchmark {$i}", 'email' => "{$batch}-{$i}@benchmark.invalid", 'password' => $password])->save();
            $project = $createProject->handle($user, ['name' => "Queue {$batch} {$i}", 'slug' => "queue-{$batch}-{$i}", 'source_type' => 'UPLOAD']);
            $copy = (string) tempnam(sys_get_temp_dir(), 'bench');
            copy($archive, $copy);
            memory_reset_peak_usage();
            $t = hrtime(true);
            $stored = $store->handle($project, $user, $copy);
            $uploads[] = (hrtime(true) - $t) / 1e6;
            $memory[] = memory_get_peak_usage();
            @unlink($copy);
            $t = hrtime(true);
            $runs[] = $start->handle($project, $user, $stored->snapshot->id, AnalysisResultType::StaticAnalysis)->run->id;
            $enqueues[] = (hrtime(true) - $t) / 1e6;
        }

        // Workers stop when nothing is due; jobs released with backoff (for
        // example ANALYZER_BUSY when more workers than analyzer slots ask at
        // once) become due later, so workers are started again until every
        // run is terminal.
        $began = microtime(true);
        $peakConnections = 0;
        $terminal = static fn (): bool => DB::table('analysis_runs')->whereIn('id', $runs)->whereIn('status', ['QUEUED', 'RUNNING'])->doesntExist();
        while (! $terminal() && microtime(true) - $began < 900) {
            $children = $this->startWorkers($workers);
            do {
                $peakConnections = max($peakConnections, (int) DB::scalar('SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()'));
                usleep(200_000);
                $children = array_filter($children, static fn (int $pid): bool => pcntl_waitpid($pid, $status, WNOHANG) === 0);
            } while ($children !== []);
            if (! $terminal()) {
                usleep(500_000);
            }
        }
        $wall = microtime(true) - $began;

        $stats = DB::table('analysis_runs')->whereIn('id', $runs)->get(['status', 'created_at', 'started_at', 'completed_at', 'metadata']);
        $wait = $exec = [];
        $retries = 0;
        foreach ($stats as $row) {
            if ($row->started_at !== null) {
                $wait[] = strtotime((string) $row->started_at) - strtotime((string) $row->created_at);
            }
            if ($row->completed_at !== null && $row->started_at !== null) {
                $exec[] = strtotime((string) $row->completed_at) - strtotime((string) $row->started_at);
            }
            $attempts = count((array) (json_decode((string) $row->metadata, true)['attempts'] ?? [1]));
            $retries += max(0, $attempts - 1);
        }
        $succeeded = $stats->where('status', 'SUCCEEDED')->count();

        return [
            $workers, $analyses,
            $this->pair($enqueues, 1), $this->pair($uploads, 1), round(max($memory) / 1048576, 1),
            $this->pair($wait, 0), $this->pair($exec, 0),
            round($succeeded / $wall * 60, 1), $retries, $analyses - $succeeded, $peakConnections,
        ];
    }

    /**
     * Forks $workers children that each run the real `queue:work` loop in
     * process (the same code a worker container runs) until nothing is due.
     * No command is executed: a fork runs this application's own code. The
     * parent drops its database and Redis connections first, so no child
     * shares (or closes) a connection with it; it reconnects on next use.
     *
     * @return list<int> child process ids
     */
    private function startWorkers(int $workers): array
    {
        DB::disconnect();
        Redis::purge();
        $children = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork a benchmark worker.');
            }
            if ($pid === 0) {
                $code = Artisan::call('queue:work', [
                    'connection' => (string) config('codedna.analysis.queue_connection'),
                    '--queue' => (string) config('codedna.analysis.queue'),
                    '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 0,
                ]);
                exit($code);
            }
            $children[] = $pid;
        }

        return $children;
    }

    /** @param  list<int|float>  $values */
    private function pair(array $values, int $decimals): string
    {
        if ($values === []) {
            return '-';
        }
        sort($values);
        $at = static fn (float $p): float => round((float) $values[(int) min(count($values) - 1, max(0, ceil($p * count($values)) - 1))], $decimals);

        return $at(0.50).' / '.$at(0.95);
    }
}
