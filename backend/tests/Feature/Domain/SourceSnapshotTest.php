<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Actions\Snapshots\RecordSourceSnapshot;
use App\Enums\SourceType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\SourceSnapshot;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SourceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function record(Project $project, string $key): SourceSnapshot
    {
        return app(RecordSourceSnapshot::class)->handle(
            project: $project,
            sourceType: SourceType::Upload,
            storageDisk: 'sources',
            storageKey: "snapshots/{$key}.zip",
            sourceHash: hash('sha256', $key),
            sizeBytes: 2048,
            fileCount: 12,
            primaryLanguage: 'php',
            metadata: ['archive_format' => 'zip'],
        );
    }

    public function test_recording_assigns_sequential_versions_per_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        $versions = [
            $this->record($project, 'a')->version,
            $this->record($project, 'b')->version,
            $this->record($other, 'c')->version,
            $this->record($project, 'd')->version,
        ];

        $this->assertSame([1, 2, 1, 3], $versions);
        $this->assertSame([3, 2, 1], $project->sourceSnapshots()->orderByDesc('version')->pluck('version')->all());
    }

    public function test_a_snapshot_belongs_to_its_project_and_stores_only_references(): void
    {
        $project = Project::factory()->create();
        $snapshot = $this->record($project, 'a')->refresh();

        $this->assertTrue($snapshot->project->is($project));
        $this->assertTrue(Str::isUlid($snapshot->id));
        $this->assertSame('snapshots/a.zip', $snapshot->storage_key);
        $this->assertSame(hash('sha256', 'a'), $snapshot->source_hash);
        $this->assertSame(['archive_format' => 'zip'], $snapshot->metadata);
        $this->assertNotNull($snapshot->created_at);
        $this->assertFalse(Schema::hasColumn('source_snapshots', 'updated_at'));
    }

    public function test_archived_projects_accept_no_new_snapshots(): void
    {
        $project = Project::factory()->archived()->create();

        $this->expectException(DomainRuleViolation::class);
        $this->record($project, 'a');
    }

    public function test_snapshots_are_immutable_through_eloquent(): void
    {
        $snapshot = SourceSnapshot::factory()->create();
        $original = $snapshot->getAttributes();

        try {
            $snapshot->forceFill(['file_count' => 999])->save();
            $this->fail('Updating a snapshot must be refused.');
        } catch (DomainRuleViolation) {
        }

        try {
            $snapshot->delete();
            $this->fail('Deleting a snapshot must be refused.');
        } catch (DomainRuleViolation) {
        }

        $this->assertSame($original['file_count'], SourceSnapshot::query()->findOrFail($snapshot->id)->getAttributes()['file_count']);
    }

    public function test_nothing_is_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);
        new SourceSnapshot(['project_id' => 'x', 'version' => 7, 'storage_key' => 'k']);
    }

    public function test_database_constraints_guard_snapshot_invariants(): void
    {
        $project = Project::factory()->create();
        $this->record($project, 'a');

        foreach ([
            ['project_id' => $project->id, 'version' => 1],                       // duplicate version
            ['project_id' => $project->id, 'version' => 0],                       // non-positive version
            ['project_id' => $project->id, 'version' => 5, 'storage_key' => 'snapshots/a.zip'], // object reused
            ['project_id' => $project->id, 'version' => 5, 'source_hash' => 'not-a-sha256'],
            ['project_id' => $project->id, 'version' => 5, 'storage_key' => '../escape.zip'],
            ['project_id' => $project->id, 'version' => 5, 'storage_key' => '/absolute.zip'],
            ['project_id' => $project->id, 'version' => 5, 'size_bytes' => -1],
            ['project_id' => (string) Str::ulid(), 'version' => 1],               // orphan
        ] as $invalid) {
            try {
                DB::transaction(fn () => SourceSnapshot::factory()->create($invalid));
                $this->fail('Expected a constraint violation for '.json_encode($invalid));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Unknown source types are refused by the database even when the enum cast is bypassed.
        $this->expectException(QueryException::class);
        DB::table('source_snapshots')->where('project_id', $project->id)->update(['source_type' => 'FTP']);
    }

    public function test_snapshots_of_one_project_form_an_ordered_history(): void
    {
        $project = Project::factory()->create();
        SourceSnapshot::factory()->count(3)->for($project)->create();

        $history = $project->sourceSnapshots()->orderBy('version')->get();

        $this->assertSame([1, 2, 3], $history->pluck('version')->all());
        $this->assertCount(3, $history->pluck('storage_key')->unique());
    }
}
