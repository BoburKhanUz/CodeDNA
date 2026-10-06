<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\AnalysisRunStatus;
use App\Enums\DnaSnapshotStatus;
use App\Enums\ProjectStatus;
use App\Enums\SourceType;
use App\Models\AnalysisRun;
use App\Models\DnaSnapshot;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-entity guarantees of the domain model (docs/architecture/data-model.md).
 */
final class DomainIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN_TABLES = ['projects', 'source_snapshots', 'analysis_runs', 'dna_snapshots'];

    public function test_factories_build_a_valid_graph_with_ulids_everywhere(): void
    {
        $dna = DnaSnapshot::factory()->create();
        $dna->load('analysisRun.sourceSnapshot.project.user');

        $run = $dna->analysisRun;
        $snapshot = $run->sourceSnapshot;
        $project = $snapshot->project;

        foreach ([$dna->id, $run->id, $snapshot->id, $project->id, $project->user->id] as $id) {
            $this->assertTrue(Str::isUlid($id));
        }
        $this->assertSame($project->id, $run->project_id);
        $this->assertSame($project->id, $dna->project_id);
        $this->assertSame($project->user_id, $dna->user_id);
    }

    public function test_foreign_keys_prevent_orphans(): void
    {
        $missing = (string) Str::ulid();

        foreach ([
            fn () => Project::factory()->create(['user_id' => $missing]),
            fn () => SourceSnapshot::factory()->create(['project_id' => $missing]),
            fn () => AnalysisRun::factory()->create(['source_snapshot_id' => $missing, 'project_id' => Project::factory()->create()->id]),
        ] as $orphan) {
            try {
                DB::transaction($orphan);
                $this->fail('Orphan record must be rejected.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_history_cannot_be_removed_by_deleting_a_parent(): void
    {
        $dna = DnaSnapshot::factory()->create();

        foreach ([
            fn () => DB::table('users')->where('id', $dna->user_id)->delete(),
            fn () => DB::table('projects')->where('id', $dna->project_id)->delete(),
            fn () => DB::table('analysis_runs')->where('id', $dna->analysis_run_id)->delete(),
        ] as $delete) {
            try {
                DB::transaction($delete);
                $this->fail('Deleting a parent of historical records must be restricted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseHas('dna_snapshots', ['id' => $dna->id]);
    }

    public function test_enum_cases_match_the_database_check_constraints(): void
    {
        $user = User::factory()->create();

        foreach (ProjectStatus::cases() as $status) {
            foreach (SourceType::cases() as $type) {
                Project::factory()->for($user)->create([
                    'status' => $status,
                    'source_type' => $type,
                    'repository_url' => $type === SourceType::Repository ? 'https://git.example.test/a.git' : null,
                ]);
            }
        }
        $this->assertSame(count(ProjectStatus::cases()) * count(SourceType::cases()), Project::query()->count());

        $snapshot = SourceSnapshot::factory()->create();
        foreach ([
            AnalysisRun::factory()->for($snapshot)->create(),
            // At most one active run per (snapshot, result type) since Phase 10.
            AnalysisRun::factory()->for($snapshot)->running()->create(['result_type' => 'static_analysis']),
            AnalysisRun::factory()->for($snapshot)->succeeded()->create(),
            AnalysisRun::factory()->for($snapshot)->failed()->create(),
            AnalysisRun::factory()->for($snapshot)->cancelled()->create(),
        ] as $run) {
            $this->assertInstanceOf(AnalysisRunStatus::class, $run->refresh()->status);
        }
        $this->assertEqualsCanonicalizing(
            array_column(AnalysisRunStatus::cases(), 'value'),
            AnalysisRun::query()->distinct()->pluck('status')->map->value->all(),
        );

        DnaSnapshot::factory()->create();
        DnaSnapshot::factory()->insufficientData()->create();
        $this->assertEqualsCanonicalizing(
            array_column(DnaSnapshotStatus::cases(), 'value'),
            DnaSnapshot::query()->distinct()->pluck('status')->map->value->all(),
        );
    }

    public function test_unknown_enum_values_are_rejected_by_the_application(): void
    {
        $this->expectException(\ValueError::class);
        Project::factory()->make(['status' => 'DELETED']);
    }

    public function test_domain_tables_have_no_column_that_could_hold_source_code(): void
    {
        $columns = DB::select(
            'select table_name, column_name, data_type from information_schema.columns where table_schema = ? and table_name = any(?)',
            ['public', '{'.implode(',', self::DOMAIN_TABLES).'}'],
        );

        foreach ($columns as $column) {
            $this->assertNotSame('bytea', $column->data_type, "{$column->table_name}.{$column->column_name}");
            $this->assertDoesNotMatchRegularExpression(
                '/^(content|contents|source|source_code|code|body|file_contents|file_data|blob|archive|archive_data)$/',
                $column->column_name,
                "{$column->table_name}.{$column->column_name} looks like it could hold source code",
            );
        }

        // Free-form JSONB is size-capped, so a source file cannot be smuggled into metadata.
        $this->expectException(QueryException::class);
        SourceSnapshot::factory()->create(['metadata' => ['file' => str_repeat('<?php echo 1; ', 2_000)]]);
    }

    public function test_relationships_are_loaded_eagerly_without_n_plus_one(): void
    {
        $project = Project::factory()->create();
        SourceSnapshot::factory()->count(3)->for($project)->create()
            ->each(fn (SourceSnapshot $snapshot) => AnalysisRun::factory()->count(2)->for($snapshot)
                ->sequence(['result_type' => 'foundation'], ['result_type' => 'static_analysis'])->create());

        // Strict mode (outside production) turns lazy loading into an exception.
        $this->expectException(LazyLoadingViolationException::class);
        $snapshots = Project::query()->with('sourceSnapshots')->findOrFail($project->id)->sourceSnapshots;
        $this->assertSame(6, $snapshots->sum(fn (SourceSnapshot $s) => $s->analysisRuns->count()));
    }

    public function test_eager_loading_the_graph_uses_one_query_per_relation(): void
    {
        $project = Project::factory()->create();
        SourceSnapshot::factory()->count(3)->for($project)->create()
            ->each(fn (SourceSnapshot $snapshot) => AnalysisRun::factory()->count(2)->for($snapshot)
                ->sequence(['result_type' => 'foundation'], ['result_type' => 'static_analysis'])->create());

        DB::enableQueryLog();
        $loaded = Project::query()->with('sourceSnapshots.analysisRuns')->findOrFail($project->id);
        $runs = $loaded->sourceSnapshots->sum(fn (SourceSnapshot $s) => $s->analysisRuns->count());

        $this->assertSame(6, $runs);
        $this->assertCount(3, DB::getQueryLog());
    }

    public function test_timestamps_follow_the_conventions(): void
    {
        $project = Project::factory()->create();
        $this->travel(5)->minutes();
        $project->update(['description' => 'Updated']);

        $this->assertTrue($project->updated_at->greaterThan($project->created_at));
        $this->assertSame('UTC', $project->created_at->getTimezone()->getName());
        $this->assertNull(SourceSnapshot::UPDATED_AT);
        $this->assertNull(DnaSnapshot::UPDATED_AT);
    }
}
