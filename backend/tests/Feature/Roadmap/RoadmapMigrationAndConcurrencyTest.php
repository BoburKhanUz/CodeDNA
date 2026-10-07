<?php

declare(strict_types=1);

namespace Tests\Feature\Roadmap;

use App\Actions\Roadmap\CompleteRoadmapStep;
use App\Actions\Roadmap\GenerateRoadmap;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ChallengeFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Committed-data tests (no RefreshDatabase transaction): concurrent
 * generation, step completion and superseding in forked processes, and
 * the Phase 17 migration's rollback and re-application. Everything created
 * is deleted.
 */
final class RoadmapMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_14_000001_create_roadmap_tables.php';

    /** @var list<Project> */
    private array $projects = [];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->dir = sys_get_temp_dir().'/codedna-roadmap-'.Str::random(8);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        foreach ($this->projects as $project) {
            $id = $project->id;
            DB::table('roadmap_step_completions')->where('project_id', $id)->delete();
            DB::table('roadmap_steps')->where('project_id', $id)->delete();
            DB::table('roadmap_snapshots')->where('project_id', $id)->delete();
            $runs = DB::table('analysis_runs')->where('project_id', $id)->pluck('id');
            DB::table('skill_gap_results')->where('project_id', $id)->delete();
            DB::table('skill_gap_snapshots')->where('project_id', $id)->delete();
            DB::table('competency_snapshots')->where('project_id', $id)->delete();
            DB::table('dna_snapshots')->where('project_id', $id)->delete();
            DB::table('analysis_results')->whereIn('analysis_run_id', $runs)->delete();
            DB::table('analysis_runs')->where('project_id', $id)->delete();
            DB::table('source_snapshots')->where('project_id', $id)->delete();
            DB::table('projects')->where('id', $id)->delete();
            DB::table('users')->where('id', $project->user_id)->delete();
        }
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function project(): Project
    {
        return $this->projects[] = Project::factory()->for(User::factory())->create();
    }

    /**
     * @param  callable(int): string  $work
     * @return list<string>
     */
    private function concurrently(int $count, callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        DB::disconnect();
        $startAt = microtime(true) + 0.5;
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::purge();
                    time_sleep_until($startAt);
                    $out = $work($i);
                } catch (Throwable $e) {
                    $out = 'error: '.$e::class.': '.$e->getMessage();
                }
                file_put_contents("{$this->dir}/{$i}", $out);
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        return array_map(fn (int $i): string => (string) @file_get_contents("{$this->dir}/{$i}"), range(0, $count - 1));
    }

    public function test_concurrent_generation_creates_one_roadmap(): void
    {
        $project = $this->project();
        ChallengeFixtures::manyGaps($project);
        $owner = User::query()->findOrFail($project->user_id);

        $results = $this->concurrently(8, fn (): string => ($generated = app(GenerateRoadmap::class)->handle($project, $owner))->created
            ? 'created:'.$generated->roadmap->id : 'existing:'.$generated->roadmap->id);

        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_starts_with($r, 'created:')), implode("\n", $results));
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode(':', $r)[1] ?? $r, $results)));
        $this->assertSame(1, RoadmapSnapshot::query()->where('project_id', $project->id)->count());
        $this->assertSame(21, DB::table('roadmap_steps')->where('project_id', $project->id)->count());
    }

    public function test_concurrent_completion_of_one_step_records_it_once(): void
    {
        $project = $this->project();
        ChallengeFixtures::oneGap($project);
        $owner = User::query()->findOrFail($project->user_id);
        $roadmap = app(GenerateRoadmap::class)->handle($project, $owner)->roadmap;

        $results = $this->concurrently(8, fn (): string => app(CompleteRoadmapStep::class)->handle($project, $roadmap, 'ch-syntax', $owner)->created ? 'created' : 'existing');

        $counts = array_count_values($results);
        ksort($counts);
        $this->assertSame(['created' => 1, 'existing' => 7], $counts, implode("\n", $results));
        $this->assertSame(1, DB::table('roadmap_step_completions')->where('roadmap_snapshot_id', $roadmap->id)->count());
    }

    /**
     * The last steps completed at the same time: the roadmap becomes
     * COMPLETED exactly once, with every completion recorded.
     */
    public function test_concurrent_final_steps_complete_the_roadmap_once(): void
    {
        $project = $this->project();
        ChallengeFixtures::oneGap($project);
        $owner = User::query()->findOrFail($project->user_id);
        $roadmap = app(GenerateRoadmap::class)->handle($project, $owner)->roadmap;
        foreach (['ch-syntax', 'ch-find-errors', 'ch-fix'] as $step) {
            app(CompleteRoadmapStep::class)->handle($project, $roadmap, $step, $owner);
        }

        $results = $this->concurrently(2, fn (int $i): string => app(CompleteRoadmapStep::class)
            ->handle($project, $roadmap, ['ch-validate', 'ch-challenge'][$i], $owner)->roadmap->status->value);
        app(CompleteRoadmapStep::class)->handle($project, $roadmap, 'ch-reassess', $owner);

        $this->assertSame(['ACTIVE', 'ACTIVE'], $results, implode("\n", $results));
        $stored = RoadmapSnapshot::query()->findOrFail($roadmap->id);
        $this->assertSame(['COMPLETED', 6], [$stored->status->value, DB::table('roadmap_step_completions')->where('roadmap_snapshot_id', $roadmap->id)->count()]);
    }

    /**
     * Superseding and completing at the same time: a superseded roadmap
     * never receives a completion after it was superseded.
     */
    public function test_superseding_and_completing_race_safely(): void
    {
        $project = $this->project();
        ChallengeFixtures::oneGap($project);
        $owner = User::query()->findOrFail($project->user_id);
        $old = app(GenerateRoadmap::class)->handle($project, $owner)->roadmap;
        $this->travel(1)->minutes();
        ChallengeFixtures::manyGaps($project);

        $results = $this->concurrently(2, fn (int $i): string => $i === 0
            ? app(GenerateRoadmap::class)->handle($project, $owner)->roadmap->id
            : (app(CompleteRoadmapStep::class)->handle($project, $old, 'ch-syntax', $owner)->created ? 'completed' : 'existing'));

        $stored = RoadmapSnapshot::query()->findOrFail($old->id);
        $this->assertSame('SUPERSEDED', $stored->status->value, implode("\n", $results));
        $this->assertSame(1, RoadmapSnapshot::query()->where('project_id', $project->id)->where('status', 'ACTIVE')->count());
        $completion = DB::table('roadmap_step_completions')->where('roadmap_snapshot_id', $old->id)->first();
        if ($completion === null) {
            $this->assertStringContainsString('This roadmap is no longer active', $results[1]);
        } else {
            $this->assertSame('completed', $results[1]);
            $this->assertLessThanOrEqual($stored->superseded_at?->getTimestampMs(), Carbon::parse($completion->completed_at)->getTimestampMs());
        }
    }

    public function test_the_migration_rolls_back_and_reapplies(): void
    {
        // reset, not rollback: it reverts the roadmap migration whatever batch it is in.
        $this->artisan('migrate:reset', ['--path' => self::MIGRATION])->assertSuccessful();
        foreach (['roadmap_snapshots', 'roadmap_steps', 'roadmap_step_completions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasTable('skill_gap_snapshots'), 'earlier phases are untouched');
        $this->assertTrue(Schema::hasTable('challenge_instances'), 'earlier phases are untouched');

        $this->artisan('migrate')->assertSuccessful();
        foreach (['roadmap_snapshots', 'roadmap_steps', 'roadmap_step_completions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }
}
