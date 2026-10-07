<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Actions\Analysis\StartAnalysis;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeAnalyzer;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency against PostgreSQL: separate processes, each with its own
 * database connection, act on the same analysis at the same moment. Rows
 * must be committed to be visible across processes, so this test does not
 * use RefreshDatabase's transaction; it deletes what it created.
 */
final class AnalysisConcurrencyTest extends TestCase
{
    private ?Project $project = null;

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
        FakeAnalyzer::configure();
        $this->resultDir = sys_get_temp_dir().'/codedna-analysis-concurrency-'.Str::random(8);
        mkdir($this->resultDir);
    }

    protected function tearDown(): void
    {
        if ($this->project !== null) {
            // Runs, results and snapshots are immutable through Eloquent; test cleanup uses the query builder.
            $runs = DB::table('analysis_runs')->where('project_id', $this->project->id)->pluck('id');
            DB::table('growth_observations')->where('project_id', $this->project->id)->delete();
            DB::table('growth_snapshots')->where('project_id', $this->project->id)->delete();
            DB::table('skill_gap_results')->whereIn('skill_gap_snapshot_id', DB::table('skill_gap_snapshots')->whereIn('analysis_run_id', $runs)->select('id'))->delete();
            DB::table('skill_gap_snapshots')->whereIn('analysis_run_id', $runs)->delete();
            DB::table('competency_snapshots')->whereIn('analysis_run_id', $runs)->delete();
            DB::table('dna_snapshots')->whereIn('analysis_run_id', $runs)->delete();
            DB::table('analysis_results')->whereIn('analysis_run_id', $runs)->delete();
            DB::table('analysis_runs')->where('project_id', $this->project->id)->delete();
            DB::table('source_snapshots')->where('project_id', $this->project->id)->delete();
            DB::table('projects')->where('id', $this->project->id)->delete();
            DB::table('users')->where('id', $this->project->user_id)->delete();
        }
        foreach (glob($this->resultDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->resultDir);
        parent::tearDown();
    }

    /**
     * Runs $work in $count child processes released at the same instant.
     *
     * @param  Closure(int): string  $work
     * @return list<string> each child's result, in child order
     */
    private function concurrently(int $count, Closure $work): array
    {
        DB::disconnect();
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
                    file_put_contents("{$this->resultDir}/{$i}", $work($i));
                } catch (Throwable $e) {
                    file_put_contents("{$this->resultDir}/{$i}", 'error: '.$e::class.': '.$e->getMessage());
                }
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

        return $results;
    }

    private function snapshot(): SourceSnapshot
    {
        $this->project ??= Project::factory()->create();

        return SourceSnapshot::factory()->for($this->project)->create();
    }

    public function test_ten_concurrent_starts_create_one_logical_analysis(): void
    {
        $snapshot = $this->snapshot();
        $user = User::query()->findOrFail($this->project?->user_id);
        Queue::fake();

        $results = $this->concurrently(10, function () use ($snapshot, $user): string {
            $started = app(StartAnalysis::class)->handle(Project::query()->findOrFail($snapshot->project_id), $user, $snapshot->id, AnalysisResultType::StaticAnalysis);

            return $started->run->id.'|'.($started->created ? 'created' : 'existing');
        });

        foreach ($results as $result) {
            $this->assertMatchesRegularExpression('/^[0-9a-z]{26}\|(created|existing)$/', $result, "a start failed: {$result}");
        }
        $ids = array_unique(array_map(fn (string $r) => explode('|', $r)[0], $results));
        $this->assertCount(1, $ids, 'every request resolved to the same run');
        $this->assertSame(1, count(array_filter($results, fn (string $r) => str_ends_with($r, '|created'))), 'exactly one request created it');
        $this->assertSame(1, AnalysisRun::query()->where('source_snapshot_id', $snapshot->id)->count());
    }

    public function test_concurrent_starts_for_different_result_types_and_snapshots_stay_independent(): void
    {
        $first = $this->snapshot();
        $second = $this->snapshot();
        $user = User::query()->findOrFail($this->project?->user_id);
        Queue::fake();
        $cases = [
            [$first, AnalysisResultType::Foundation], [$first, AnalysisResultType::StaticAnalysis],
            [$second, AnalysisResultType::Foundation], [$second, AnalysisResultType::StaticAnalysis],
        ];

        $results = $this->concurrently(8, function (int $i) use ($cases, $user): string {
            [$snapshot, $type] = $cases[$i % 4];

            return app(StartAnalysis::class)->handle(Project::query()->findOrFail($snapshot->project_id), $user, $snapshot->id, $type)->run->id;
        });

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($results[$i], $results[$i + 4], 'the same pair resolves to the same run');
        }
        $this->assertCount(4, array_unique($results));
        $this->assertSame(4, AnalysisRun::query()->where('project_id', $this->project?->id)->count());
    }

    public function test_two_workers_running_the_same_run_analyze_and_persist_it_once(): void
    {
        $snapshot = $this->snapshot();
        $run = AnalysisRun::factory()->for($snapshot)->create(['result_type' => AnalysisResultType::StaticAnalysis]);
        $calls = $this->resultDir.'/analyzer-calls';
        Http::fake(['http://analyzer:8000/*' => function (Request $request) use ($calls) {
            file_put_contents($calls, "call\n", FILE_APPEND | LOCK_EX);
            usleep(300_000);

            return FakeAnalyzer::success($request);
        }]);

        // Two dispatches of the same run (e.g. a duplicate job), executed at once.
        $results = $this->concurrently(2, function () use ($run): string {
            app()->call([new AnalyzeSourceSnapshot($run->id), 'handle']);

            return 'done';
        });

        $this->assertSame(['done', 'done'], $results);
        $this->assertSame(1, substr_count((string) file_get_contents($calls), 'call'), 'the analyzer was called once');
        @unlink($calls);
        $run->refresh();
        $this->assertSame(AnalysisRunStatus::Succeeded, $run->status);
        $this->assertCount(1, $run->metadata['attempts']);
        $this->assertSame(1, AnalysisResult::query()->where('analysis_run_id', $run->id)->count());
    }

    public function test_the_database_allows_one_active_run_per_snapshot_and_result_type(): void
    {
        $snapshot = $this->snapshot();
        AnalysisRun::factory()->for($snapshot)->create();

        $this->expectException(UniqueConstraintViolationException::class);
        AnalysisRun::factory()->for($snapshot)->running()->create();
    }
}
