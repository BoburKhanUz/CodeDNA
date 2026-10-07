<?php

declare(strict_types=1);

namespace Tests\Feature\Dna;

use App\Models\AnalysisRun;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Phase 11 migration (2026_10_09_000001_add_scoring_to_dna_snapshots):
 * fresh install, rollback, and upgrade of existing rows. Migrations commit,
 * so this test does not use RefreshDatabase; it leaves the schema fully
 * migrated and deletes what it created.
 */
final class DnaSnapshotMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_09_000001_add_scoring_to_dna_snapshots.php';

    private const COMPETENCY_MIGRATION = 'database/migrations/2026_10_10_000001_create_competency_snapshots_table.php';

    private const SKILL_GAP_MIGRATION = 'database/migrations/2026_10_11_000001_create_skill_gap_tables.php';

    private const CHALLENGE_MIGRATION = 'database/migrations/2026_10_13_000001_create_challenge_tables.php';

    private const ROADMAP_MIGRATION = 'database/migrations/2026_10_14_000001_create_roadmap_tables.php';

    private const ASSESSMENT_MIGRATION = 'database/migrations/2026_10_12_000001_create_ai_assessments_table.php';

    /** @var list<string> */
    private array $projects = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        foreach ($this->projects as $projectId) {
            $userId = DB::table('projects')->where('id', $projectId)->value('user_id');
            DB::table('dna_snapshots')->where('project_id', $projectId)->delete();
            DB::table('analysis_runs')->where('project_id', $projectId)->delete();
            DB::table('source_snapshots')->where('project_id', $projectId)->delete();
            DB::table('projects')->where('id', $projectId)->delete();
            DB::table('users')->where('id', $userId)->delete();
        }
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function constraints(): array
    {
        return array_map(fn (object $row): string => $row->conname, DB::select(
            "select conname from pg_constraint where conrelid = 'dna_snapshots'::regclass order by conname",
        ));
    }

    /**
     * @return list<string>
     */
    private function uniqueIndexes(): array
    {
        return array_map(fn (object $row): string => $row->indexdef, DB::select(
            "select indexdef from pg_indexes where tablename = 'dna_snapshots' and indexdef like 'CREATE UNIQUE%' order by indexname",
        ));
    }

    public function test_a_fresh_install_has_the_scoring_columns_and_constraints(): void
    {
        $this->assertTrue(Schema::hasColumns('dna_snapshots', ['source_snapshot_id', 'data_quality']));
        $this->assertSame('NO', DB::scalar("select is_nullable from information_schema.columns where table_name = 'dna_snapshots' and column_name = 'source_snapshot_id'"));
        $this->assertSame('YES', DB::scalar("select is_nullable from information_schema.columns where table_name = 'dna_snapshots' and column_name = 'data_quality'"));
        $this->assertContains('dna_snapshots_data_quality_range', $this->constraints());
        $this->assertContains('dna_snapshots_source_snapshot_id_project_id_foreign', $this->constraints());
        $this->assertContains('dna_snapshots_analysis_run_id_scoring_version_unique', $this->constraints());
        $this->assertNotContains('dna_snapshots_analysis_run_id_unique', $this->constraints());
        // No cascading deletes anywhere on the table.
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_constraint where conrelid = 'dna_snapshots'::regclass and contype = 'f' and confdeltype <> 'r'"));
    }

    public function test_rollback_restores_the_phase_05_shape_and_the_upgrade_backfills_existing_rows(): void
    {
        // The skill gap (Phase 14) and competency (Phase 13) tables depend on dna_snapshots and are rolled back first.
        // reset, not rollback: it reverts the roadmap migration whatever batch it is in.
        $this->artisan('migrate:reset', ['--path' => self::ROADMAP_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::CHALLENGE_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::ASSESSMENT_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::SKILL_GAP_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::COMPETENCY_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('dna_snapshots', 'source_snapshot_id'));
        $this->assertFalse(Schema::hasColumn('dna_snapshots', 'data_quality'));
        $this->assertContains('dna_snapshots_analysis_run_id_unique', $this->constraints());
        $this->assertNotContains('dna_snapshots_analysis_run_id_scoring_version_unique', $this->constraints());

        // A row written before Phase 11.
        $run = AnalysisRun::factory()->succeeded()->create();
        $this->projects[] = $run->project_id;
        $userId = DB::table('projects')->where('id', $run->project_id)->value('user_id');
        $dnaId = strtolower((string) Str::ulid());
        DB::table('dna_snapshots')->insert([
            'id' => $dnaId, 'user_id' => $userId, 'project_id' => $run->project_id, 'analysis_run_id' => $run->id,
            'scoring_version' => '1.0', 'status' => 'INSUFFICIENT_DATA', 'overall_score' => null,
            'dimensions' => '{}', 'result_hash' => $run->result_hash,
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $row = DB::table('dna_snapshots')->where('id', $dnaId)->first();
        $this->assertNotNull($row);
        $this->assertSame($run->source_snapshot_id, $row->source_snapshot_id);
        $this->assertNull($row->data_quality);
        $this->assertSame('1.0', $row->scoring_version);
        $this->assertContains('dna_snapshots_analysis_run_id_scoring_version_unique', $this->constraints());
        $this->assertCount(1, array_filter($this->uniqueIndexes(), fn (string $def): bool => str_contains($def, '(analysis_run_id, scoring_version)')));
    }
}
