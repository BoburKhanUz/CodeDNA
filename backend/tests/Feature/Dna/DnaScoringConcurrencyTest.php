<?php

declare(strict_types=1);

namespace Tests\Feature\Dna;

use App\Actions\Dna\CalculateDnaSnapshot;
use App\Models\AnalysisRun;
use App\Models\DnaSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BillingFixtures;
use Tests\Support\StoredResults;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency against PostgreSQL: separate processes, each with its own
 * connection, score the same run at the same moment. Rows must be committed
 * to be visible across processes, so this test does not use
 * RefreshDatabase's transaction; it deletes what it created.
 */
final class DnaScoringConcurrencyTest extends TestCase
{
    private ?AnalysisRun $run = null;

    private string $resultDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->resultDir = sys_get_temp_dir().'/codedna-dna-concurrency-'.Str::random(8);
        mkdir($this->resultDir);
    }

    protected function tearDown(): void
    {
        if ($this->run !== null) {
            // Snapshots, results and runs are immutable through Eloquent; test cleanup uses the query builder.
            $project = DB::table('projects')->where('id', $this->run->project_id)->first();
            DB::table('dna_snapshots')->where('project_id', $this->run->project_id)->delete();
            DB::table('analysis_results')->where('analysis_run_id', $this->run->id)->delete();
            DB::table('analysis_runs')->where('project_id', $this->run->project_id)->delete();
            DB::table('source_snapshots')->where('project_id', $this->run->project_id)->delete();
            DB::table('projects')->where('id', $this->run->project_id)->delete();
            BillingFixtures::forget([(string) $project?->user_id]);
            DB::table('users')->where('id', $project?->user_id)->delete();
        }
        foreach (glob($this->resultDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->resultDir);
        parent::tearDown();
    }

    public function test_concurrent_scoring_creates_exactly_one_snapshot(): void
    {
        $this->run = StoredResults::succeededRun('static_analysis', CalculateDnaSnapshotTest::rateable(...));
        $runId = $this->run->id;

        DB::disconnect();
        $count = 8;
        $startAt = microtime(true) + 0.5;
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    time_sleep_until($startAt);
                    $calculated = app(CalculateDnaSnapshot::class)->handle($runId);
                    $out = $calculated->snapshot->id.'|'.($calculated->created ? 'created' : 'existing');
                } catch (Throwable $e) {
                    $out = 'error: '.$e::class.': '.$e->getMessage();
                }
                file_put_contents("{$this->resultDir}/{$i}", $out);
                // Skip PHPUnit's shutdown handlers in the child.
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = (string) @file_get_contents("{$this->resultDir}/{$i}");
        }
        foreach ($results as $result) {
            $this->assertMatchesRegularExpression('/^[0-9a-z]{26}\|(created|existing)$/', $result, "a scoring call failed: {$result}");
        }
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode('|', $r)[0], $results)), 'every call returned the same snapshot');
        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_ends_with($r, '|created')), 'exactly one call created it');
        $this->assertSame(1, DnaSnapshot::query()->where('analysis_run_id', $runId)->count());
        $this->assertSame('0.8050', DnaSnapshot::query()->where('analysis_run_id', $runId)->sole()->overall_score);
    }
}
