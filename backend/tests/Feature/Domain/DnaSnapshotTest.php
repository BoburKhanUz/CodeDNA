<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\DnaSnapshotStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\AnalysisRun;
use App\Models\DnaSnapshot;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DnaSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dna_snapshot_belongs_to_its_run_project_and_user(): void
    {
        $dna = DnaSnapshot::factory()->create()->refresh();
        $run = $dna->analysisRun;

        $this->assertTrue($run->dnaSnapshot->is($dna));
        $this->assertTrue($dna->project->is($run->project));
        $this->assertTrue($dna->user->is($run->project->user));
        $this->assertSame(DnaSnapshotStatus::Ready, $dna->status);
        $this->assertSame('0.5000', $dna->overall_score);
        $this->assertSame(['fixture' => true], $dna->evidence);
        $this->assertFalse(Schema::hasColumn('dna_snapshots', 'updated_at'));
    }

    public function test_a_run_produces_at_most_one_dna_snapshot_per_scoring_version(): void
    {
        $dna = DnaSnapshot::factory()->create();
        DnaSnapshot::factory()->create(['analysis_run_id' => $dna->analysis_run_id, 'scoring_version' => '1.0.0']);
        $this->assertSame(2, $dna->analysisRun->dnaSnapshots()->count());

        $this->expectException(UniqueConstraintViolationException::class);
        DnaSnapshot::factory()->create(['analysis_run_id' => $dna->analysis_run_id]);
    }

    public function test_the_source_snapshot_must_be_the_runs_and_data_quality_is_bounded(): void
    {
        $dna = DnaSnapshot::factory()->create()->refresh();
        $this->assertSame($dna->analysisRun->source_snapshot_id, $dna->source_snapshot_id);
        $this->assertNull($dna->data_quality);

        $run = AnalysisRun::factory()->succeeded()->create();
        foreach ([
            ['source_snapshot_id' => SourceSnapshot::factory()->create()->id],
            ['data_quality' => '1.0001'],
            ['data_quality' => '-0.0001'],
        ] as $invalid) {
            try {
                DB::transaction(fn () => DnaSnapshot::factory()->create(['analysis_run_id' => $run->id, ...$invalid]));
                $this->fail('Expected a constraint violation for '.json_encode($invalid));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_succeeded_runs_produce_dna(): void
    {
        $run = AnalysisRun::factory()->running()->create();

        $this->expectException(DomainRuleViolation::class);
        DnaSnapshot::factory()->create(['analysis_run_id' => $run->id]);
    }

    public function test_a_project_keeps_a_history_of_dna_snapshots(): void
    {
        $project = Project::factory()->create();
        $first = SourceSnapshot::factory()->for($project)->create();
        $second = SourceSnapshot::factory()->for($project)->create();
        foreach ([$first, $first, $second] as $snapshot) {
            DnaSnapshot::factory()->create([
                'analysis_run_id' => AnalysisRun::factory()->for($snapshot)->succeeded()->create()->id,
            ]);
        }

        $this->assertSame(3, $project->dnaSnapshots()->count());
        $this->assertSame(3, DnaSnapshot::query()->where('user_id', $project->user_id)->count());
    }

    public function test_dna_cannot_be_attributed_to_another_user_or_project(): void
    {
        $run = AnalysisRun::factory()->succeeded()->create();
        $stranger = User::factory()->create();
        $otherProject = Project::factory()->for($stranger)->create();

        foreach ([['user_id' => $stranger->id], ['project_id' => $otherProject->id, 'user_id' => $stranger->id]] as $invalid) {
            try {
                DB::transaction(fn () => DnaSnapshot::factory()->create(['analysis_run_id' => $run->id, ...$invalid]));
                $this->fail('Mismatched ownership must be rejected.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_score_and_status_must_agree(): void
    {
        $insufficient = DnaSnapshot::factory()->insufficientData()->create();
        $this->assertNull($insufficient->refresh()->overall_score);

        foreach ([
            ['status' => 'READY', 'overall_score' => null],
            ['status' => 'INSUFFICIENT_DATA', 'overall_score' => '0.5000'],
            ['overall_score' => '1.5000'],
            ['result_hash' => 'not-a-hash'],
        ] as $invalid) {
            try {
                DB::transaction(fn () => DnaSnapshot::factory()->create($invalid));
                $this->fail('Expected a constraint violation for '.json_encode($invalid));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_structured_results_are_stored_as_jsonb(): void
    {
        $dna = DnaSnapshot::factory()->create([
            'dimensions' => ['naming' => ['status' => 'scored', 'score' => '0.9300', 'evidence' => ['identifiers' => 1830]]],
            'strengths' => [['dimension' => 'naming']],
        ])->refresh();

        $this->assertSame('0.9300', $dna->dimensions['naming']['score']);
        $this->assertSame([['dimension' => 'naming']], $dna->strengths);
        $this->assertSame('jsonb', DB::scalar('select pg_typeof(dimensions)::text from dna_snapshots where id = ?', [$dna->id]));
        $this->assertSame(1, DnaSnapshot::query()->where('dimensions->naming->status', 'scored')->count());
    }

    public function test_dna_snapshots_are_immutable(): void
    {
        $dna = DnaSnapshot::factory()->create();

        foreach ([
            fn () => $dna->forceFill(['overall_score' => '0.9999'])->save(),
            fn () => $dna->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('DNA snapshots must not change.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('0.5000', DnaSnapshot::query()->findOrFail($dna->id)->overall_score);
        $this->expectException(MassAssignmentException::class);
        new DnaSnapshot(['overall_score' => '1.0000']);
    }
}
