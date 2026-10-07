<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingFixtures;
use Tests\Support\ChallengeFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Committed-data tests (no RefreshDatabase transaction): concurrent growth
 * calculation in forked processes, and the Phase 18 migration's rollback
 * and re-application. Everything created is deleted.
 */
final class GrowthMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_15_000001_create_growth_tables.php';

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
        $this->dir = sys_get_temp_dir().'/codedna-growth-'.Str::random(8);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        foreach ($this->projects as $project) {
            $id = $project->id;
            DB::table('growth_observations')->where('project_id', $id)->delete();
            DB::table('growth_snapshots')->where('project_id', $id)->delete();
            $runs = DB::table('analysis_runs')->where('project_id', $id)->pluck('id');
            DB::table('skill_gap_results')->where('project_id', $id)->delete();
            DB::table('skill_gap_snapshots')->where('project_id', $id)->delete();
            DB::table('competency_snapshots')->where('project_id', $id)->delete();
            DB::table('dna_snapshots')->where('project_id', $id)->delete();
            DB::table('analysis_results')->whereIn('analysis_run_id', $runs)->delete();
            DB::table('analysis_runs')->where('project_id', $id)->delete();
            DB::table('source_snapshots')->where('project_id', $id)->delete();
            DB::table('projects')->where('id', $id)->delete();
            BillingFixtures::forget([(string) $project->user_id]);
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

    /**
     * @return array<string, array{0: int}>
     */
    public static function workers(): array
    {
        return ['2 workers' => [2], '8 workers' => [8]];
    }

    #[DataProvider('workers')]
    public function test_concurrent_calculation_creates_exactly_one_snapshot(int $workers): void
    {
        $project = $this->project();
        ChallengeFixtures::manyGaps($project);
        $this->travel(1)->hours();
        $gaps = ChallengeFixtures::oneGap($project);

        $results = $this->concurrently($workers, fn (): string => ($calculated = app(CalculateGrowthSnapshot::class)->handle($gaps->id))->created
            ? 'created:'.$calculated->snapshot->id : 'existing:'.$calculated->snapshot->id);

        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_starts_with($r, 'created:')), implode("\n", $results));
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode(':', $r)[1] ?? $r, $results)), implode("\n", $results));
        $snapshot = GrowthSnapshot::query()->where('project_id', $project->id)->sole();
        $this->assertSame('COMPARED', $snapshot->status->value);
        $this->assertSame($snapshot->summary['observations'], DB::table('growth_observations')->where('growth_snapshot_id', $snapshot->id)->count());
    }

    public function test_the_migration_rolls_back_and_reapplies(): void
    {
        $this->artisan('migrate:reset', ['--path' => self::MIGRATION])->assertSuccessful();
        foreach (['growth_snapshots', 'growth_observations'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertSame([], DB::select("SELECT proname FROM pg_proc WHERE proname LIKE 'growth_%'"));
        // Everything before Phase 18 is untouched.
        foreach (['skill_gap_snapshots', 'roadmap_snapshots', 'challenge_instances'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('growth_snapshots'));
        $this->assertTrue(Schema::hasTable('growth_observations'));

        $project = $this->project();
        $gaps = ChallengeFixtures::oneGap($project);
        $this->assertSame('NOT_ESTABLISHED', app(CalculateGrowthSnapshot::class)->handle($gaps->id)->snapshot->status->value);
    }
}
