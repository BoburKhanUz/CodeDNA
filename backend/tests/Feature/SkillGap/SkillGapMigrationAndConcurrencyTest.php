<?php

declare(strict_types=1);

namespace Tests\Feature\SkillGap;

use App\Actions\Competency\CalculateCompetencyMatrix;
use App\Actions\Dna\CalculateDnaSnapshot;
use App\Actions\SkillGap\CalculateSkillGapSnapshot;
use App\Models\CompetencySnapshot;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Dna\CalculateDnaSnapshotTest;
use Tests\Support\StoredResults;
use Tests\TestCase;
use Throwable;

/**
 * Committed-data tests (no RefreshDatabase transaction): real concurrency
 * with forked processes, and the Phase 14 migration's fresh install,
 * rollback, re-application and backfill. Everything created is deleted.
 */
final class SkillGapMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_11_000001_create_skill_gap_tables.php';

    private const CHALLENGE_MIGRATION = 'database/migrations/2026_10_13_000001_create_challenge_tables.php';

    private const ROADMAP_MIGRATION = 'database/migrations/2026_10_14_000001_create_roadmap_tables.php';

    private const ASSESSMENT_MIGRATION = 'database/migrations/2026_10_12_000001_create_ai_assessments_table.php';

    private ?CompetencySnapshot $competency = null;

    private string $resultDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->resultDir = sys_get_temp_dir().'/codedna-skill-gap-'.Str::random(8);
        mkdir($this->resultDir);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        if ($this->competency !== null) {
            $projectId = $this->competency->project_id;
            DB::table('skill_gap_results')->where('project_id', $projectId)->delete();
            DB::table('skill_gap_snapshots')->where('project_id', $projectId)->delete();
            DB::table('competency_snapshots')->where('project_id', $projectId)->delete();
            DB::table('dna_snapshots')->where('project_id', $projectId)->delete();
            DB::table('analysis_results')->where('analysis_run_id', $this->competency->analysis_run_id)->delete();
            DB::table('analysis_runs')->where('project_id', $projectId)->delete();
            DB::table('source_snapshots')->where('project_id', $projectId)->delete();
            DB::table('projects')->where('id', $projectId)->delete();
            DB::table('users')->where('id', $this->competency->user_id)->delete();
        }
        foreach (glob($this->resultDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->resultDir);
        parent::tearDown();
    }

    private function competency(): CompetencySnapshot
    {
        $run = StoredResults::succeededRun('static_analysis', CalculateDnaSnapshotTest::rateable(...));
        $dna = app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;

        return $this->competency = app(CalculateCompetencyMatrix::class)->handle($dna->id)->snapshot;
    }

    public function test_concurrent_calculation_creates_exactly_one_snapshot_with_its_results(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        $competencyId = $this->competency()->id;

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
                    $calculated = app(CalculateSkillGapSnapshot::class)->handle($competencyId);
                    $out = $calculated->snapshot->id.'|'.($calculated->created ? 'created' : 'existing');
                } catch (Throwable $e) {
                    $out = 'error: '.$e::class.': '.$e->getMessage();
                }
                file_put_contents("{$this->resultDir}/{$i}", $out);
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
            $this->assertMatchesRegularExpression('/^[0-9a-z]{26}\|(created|existing)$/', $result, "a call failed: {$result}");
        }
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode('|', $r)[0], $results)));
        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_ends_with($r, '|created')));
        $snapshot = SkillGapSnapshot::query()->where('competency_snapshot_id', $competencyId)->sole();
        $this->assertSame(4, SkillGapResult::query()->where('skill_gap_snapshot_id', $snapshot->id)->count());
    }

    public function test_fresh_install_rollback_reapplication_and_backfill(): void
    {
        $constraints = fn (string $table): array => array_map(fn (object $r): string => $r->conname, DB::select(
            'select conname from pg_constraint where conrelid = ?::regclass order by conname', [$table],
        ));
        foreach (['skill_gap_snapshots_lineage_foreign', 'skill_gap_snapshots_competency_version_profile_unique',
            'skill_gap_snapshots_status_valid', 'skill_gap_snapshots_fingerprint_sha256'] as $name) {
            $this->assertContains($name, $constraints('skill_gap_snapshots'));
        }
        foreach (['skill_gap_results_snapshot_foreign', 'skill_gap_results_raw_gap_formula', 'skill_gap_results_gap_iff_material',
            'skill_gap_results_priority_iff_gap', 'skill_gap_results_measured_iff_gap_status', 'skill_gap_results_target_iff_targeted'] as $name) {
            $this->assertContains($name, $constraints('skill_gap_results'));
        }
        $this->assertContains('competency_snapshots_lineage_unique', $constraints('competency_snapshots'));
        foreach (['skill_gap_snapshots', 'skill_gap_results'] as $table) {
            $this->assertSame(0, (int) DB::scalar("select count(*) from pg_constraint where conrelid = ?::regclass and contype = 'f' and confdeltype <> 'r'", [$table]), "{$table}: no cascades");
        }

        $competency = $this->competency();
        // reset, not rollback: it reverts the roadmap migration whatever batch it is in.
        $this->artisan('migrate:reset', ['--path' => self::ROADMAP_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::CHALLENGE_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::ASSESSMENT_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('skill_gap_snapshots'));
        $this->assertFalse(Schema::hasTable('skill_gap_results'));
        $this->assertNotContains('competency_snapshots_lineage_unique', $constraints('competency_snapshots'));
        $this->assertSame(1, DB::table('competency_snapshots')->where('id', $competency->id)->count(), 'competency history is untouched');

        $this->artisan('migrate')->assertSuccessful();
        // Backfill an existing competency snapshot after the upgrade.
        $this->artisan('skill-gap:calculate', ['--missing' => true])->expectsOutputToContain("{$competency->id} created GAPS_IDENTIFIED")->assertSuccessful();
        $this->assertSame(4, SkillGapResult::query()->where('project_id', $competency->project_id)->count());

        // History cannot be removed from under the analysis (RESTRICT).
        $this->expectException(QueryException::class);
        DB::table('competency_snapshots')->where('id', $competency->id)->delete();
    }
}
