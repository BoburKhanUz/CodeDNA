<?php

declare(strict_types=1);

namespace Tests\Feature\Competency;

use App\Actions\Competency\CalculateCompetencyMatrix;
use App\Actions\Dna\CalculateDnaSnapshot;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
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
 * with forked processes, and the Phase 13 migration's rollback and
 * re-application. Everything created is deleted again.
 */
final class CompetencyMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_10_000001_create_competency_snapshots_table.php';

    private const SKILL_GAP_MIGRATION = 'database/migrations/2026_10_11_000001_create_skill_gap_tables.php';

    private ?DnaSnapshot $dna = null;

    private string $resultDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->resultDir = sys_get_temp_dir().'/codedna-competency-'.Str::random(8);
        mkdir($this->resultDir);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        if ($this->dna !== null) {
            DB::table('competency_snapshots')->where('project_id', $this->dna->project_id)->delete();
            DB::table('dna_snapshots')->where('project_id', $this->dna->project_id)->delete();
            DB::table('analysis_results')->where('analysis_run_id', $this->dna->analysis_run_id)->delete();
            DB::table('analysis_runs')->where('project_id', $this->dna->project_id)->delete();
            DB::table('source_snapshots')->where('project_id', $this->dna->project_id)->delete();
            DB::table('projects')->where('id', $this->dna->project_id)->delete();
            DB::table('users')->where('id', $this->dna->user_id)->delete();
        }
        foreach (glob($this->resultDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->resultDir);
        parent::tearDown();
    }

    private function dna(): DnaSnapshot
    {
        $run = StoredResults::succeededRun('static_analysis', CalculateDnaSnapshotTest::rateable(...));

        return $this->dna = app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;
    }

    public function test_concurrent_calculation_creates_exactly_one_snapshot(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        $dnaId = $this->dna()->id;

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
                    $calculated = app(CalculateCompetencyMatrix::class)->handle($dnaId);
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
        $this->assertSame(1, CompetencySnapshot::query()->where('dna_snapshot_id', $dnaId)->count());
    }

    public function test_fresh_install_rollback_and_reapplication(): void
    {
        $this->assertTrue(Schema::hasTable('competency_snapshots'));
        $constraints = fn (string $table): array => array_map(fn (object $r): string => $r->conname, DB::select(
            'select conname from pg_constraint where conrelid = ?::regclass order by conname', [$table],
        ));
        foreach (['competency_snapshots_lineage_foreign', 'competency_snapshots_dna_snapshot_id_competency_version_unique',
            'competency_snapshots_status_valid', 'competency_snapshots_fingerprint_sha256', 'competency_snapshots_competencies_array'] as $name) {
            $this->assertContains($name, $constraints('competency_snapshots'));
        }
        $this->assertContains('dna_snapshots_lineage_unique', $constraints('dna_snapshots'));
        // No cascading deletes.
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_constraint where conrelid = 'competency_snapshots'::regclass and contype = 'f' and confdeltype <> 'r'"));

        $dna = $this->dna();
        // The skill gap tables (Phase 14) depend on competency_snapshots and are rolled back first.
        $this->artisan('migrate:rollback', ['--path' => self::SKILL_GAP_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('competency_snapshots'));
        $this->assertNotContains('dna_snapshots_lineage_unique', $constraints('dna_snapshots'));
        $this->assertSame(1, DB::table('dna_snapshots')->where('id', $dna->id)->count(), 'DNA history is untouched');

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('competency_snapshots'));
        // Upgrade path: an existing DNA snapshot is assessed after the migration.
        $this->artisan('competency:calculate', ['dna_snapshot' => $dna->id])->expectsOutputToContain('created ASSESSED')->assertSuccessful();
        // A DNA snapshot with a competency snapshot cannot be deleted (RESTRICT).
        $this->expectException(QueryException::class);
        DB::table('dna_snapshots')->where('id', $dna->id)->delete();
    }
}
