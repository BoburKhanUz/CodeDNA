<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Support\Facades\DB;

/**
 * Removes the benchmark's objects from source storage (Phase 26): every
 * object a source snapshot of the benchmark database points at (template,
 * queue benchmark and load-test uploads), and everything under the
 * benchmark key prefix. `make benchmark-clean` runs it, then drops the
 * database. Cloned snapshots point at keys with no object behind them
 * ("benchmark/no-object/…") and need nothing.
 */
final class CleanBenchmark extends Command
{
    protected $signature = 'benchmark:clean';

    protected $description = 'Delete the benchmark database\'s stored source objects';

    public function handle(BenchmarkGuard $guard, Filesystems $filesystems): int
    {
        $guard->assertBenchmarkDatabase();
        $deleted = 0;
        DB::table('source_snapshots')->where('storage_key', 'not like', 'benchmark/no-object/%')
            ->select(['id', 'storage_disk', 'storage_key'])->orderBy('id')
            ->chunkById(500, function ($rows) use ($filesystems, &$deleted): void {
                foreach ($rows->groupBy('storage_disk') as $disk => $objects) {
                    $filesystems->disk((string) $disk)->delete($objects->pluck('storage_key')->all());
                    $deleted += $objects->count();
                }
            });
        $filesystems->disk('sources')->deleteDirectory('benchmark');
        $this->info("Deleted {$deleted} stored source object(s) and the benchmark/ prefix.");

        return self::SUCCESS;
    }
}
