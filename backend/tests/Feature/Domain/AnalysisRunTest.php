<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\AnalysisRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Support\AnalysisVersions;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AnalysisRunTest extends TestCase
{
    use RefreshDatabase;

    private function versions(): AnalysisVersions
    {
        return new AnalysisVersions(analyzer: '0.1.0', ir: '1.0', metrics: '1.0', scoring: '1.0', contract: '1.0');
    }

    public function test_a_run_belongs_to_its_project_and_snapshot_and_starts_queued(): void
    {
        $snapshot = SourceSnapshot::factory()->create();
        $run = AnalysisRun::factory()->for($snapshot)->create()->refresh();

        $this->assertSame(AnalysisRunStatus::Queued, $run->status);
        $this->assertTrue($run->sourceSnapshot->is($snapshot));
        $this->assertTrue($run->project->is($snapshot->project));
        $this->assertNull($run->started_at);
    }

    public function test_a_snapshot_may_have_several_runs(): void
    {
        $snapshot = SourceSnapshot::factory()->create();
        AnalysisRun::factory()->for($snapshot)->succeeded()->create();
        AnalysisRun::factory()->for($snapshot)->failed()->create();
        AnalysisRun::factory()->for($snapshot)->create();

        $this->assertSame(3, $snapshot->analysisRuns()->count());
        $this->assertSame(3, $snapshot->project->analysisRuns()->count());
    }

    public function test_a_run_cannot_reference_a_snapshot_of_another_project(): void
    {
        $snapshot = SourceSnapshot::factory()->create();
        $otherProject = Project::factory()->create();

        $this->expectException(QueryException::class);
        AnalysisRun::factory()->create(['source_snapshot_id' => $snapshot->id, 'project_id' => $otherProject->id]);
    }

    public function test_successful_lifecycle_records_timestamps_versions_and_result(): void
    {
        $run = AnalysisRun::factory()->create();

        $run->markRunning();
        $this->assertSame(AnalysisRunStatus::Running, $run->refresh()->status);
        $this->assertNotNull($run->started_at);

        $run->markSucceeded($this->versions(), hash('sha256', 'result'));
        $run->refresh();

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->status);
        $this->assertSame(hash('sha256', 'result'), $run->result_hash);
        $this->assertSame(['0.1.0', '1.0', '1.0', '1.0', '1.0'], [
            $run->analyzer_version, $run->ir_version, $run->metrics_version, $run->scoring_version, $run->contract_version,
        ]);
        $this->assertNotNull($run->completed_at);
        $this->assertNull($run->failed_at);
    }

    public function test_failure_records_code_and_a_bounded_message(): void
    {
        $run = AnalysisRun::factory()->running()->create();

        $run->markFailed('ANALYSIS_TIMEOUT', str_repeat('m', 5_000));
        $run->refresh();

        $this->assertSame(AnalysisRunStatus::Failed, $run->status);
        $this->assertSame('ANALYSIS_TIMEOUT', $run->failure_code);
        $this->assertSame(AnalysisRun::MAX_FAILURE_MESSAGE_LENGTH, mb_strlen((string) $run->failure_message));
        $this->assertNotNull($run->failed_at);
        $this->assertNotNull($run->completed_at);
    }

    public function test_queued_runs_can_fail_or_be_cancelled(): void
    {
        $failed = AnalysisRun::factory()->create();
        $failed->markFailed('DISPATCH_FAILED');
        $cancelled = AnalysisRun::factory()->create();
        $cancelled->cancel();

        $this->assertSame(AnalysisRunStatus::Failed, $failed->refresh()->status);
        $this->assertSame(AnalysisRunStatus::Cancelled, $cancelled->refresh()->status);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $queued = AnalysisRun::factory()->create();

        $this->expectException(DomainRuleViolation::class);
        $queued->markSucceeded($this->versions(), hash('sha256', 'x')); // must run first
    }

    public function test_terminal_runs_are_immutable_and_runs_are_never_deleted(): void
    {
        $run = AnalysisRun::factory()->succeeded()->create();
        $originalHash = $run->result_hash;

        foreach ([
            fn () => $run->markRunning(),
            fn () => $run->markFailed('INTERNAL_ERROR'),
            fn () => $run->forceFill(['metadata' => ['note' => 'edited']])->save(),
            fn () => $run->forceFill(['result_hash' => hash('sha256', 'tampered')])->save(),
            fn () => $run->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('A terminal run must not change.');
            } catch (DomainRuleViolation) {
                $run->refresh();
            }
        }

        $this->assertSame($originalHash, AnalysisRun::query()->findOrFail($run->id)->result_hash);

        $this->expectException(DomainRuleViolation::class);
        AnalysisRun::factory()->create()->delete();
    }

    public function test_every_transition_matches_the_documented_lifecycle(): void
    {
        $allowed = [
            'QUEUED' => ['RUNNING', 'FAILED', 'CANCELLED'],
            'RUNNING' => ['SUCCEEDED', 'FAILED', 'CANCELLED'],
            'SUCCEEDED' => [], 'FAILED' => [], 'CANCELLED' => [],
        ];

        foreach (AnalysisRunStatus::cases() as $from) {
            $this->assertSame($allowed[$from->value] === [], $from->isTerminal());
            foreach (AnalysisRunStatus::cases() as $to) {
                $this->assertSame(in_array($to->value, $allowed[$from->value], true), $from->canTransitionTo($to), "{$from->value} -> {$to->value}");
            }
        }
    }

    public function test_database_constraints_keep_status_and_fields_consistent(): void
    {
        foreach ([
            ['status' => 'SUCCEEDED'],                                  // no result, versions or timestamps
            ['status' => 'FAILED', 'completed_at' => now()],            // no failure code / failed_at
            ['status' => 'RUNNING'],                                    // not started
            ['status' => 'QUEUED', 'result_hash' => hash('sha256', 'x')],
            ['status' => 'QUEUED', 'failure_message' => 'x'],
        ] as $invalid) {
            try {
                DB::transaction(fn () => AnalysisRun::factory()->create($invalid));
                $this->fail('Expected a constraint violation for '.json_encode($invalid));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Unknown states are refused by the database even when the enum cast is bypassed.
        $this->expectException(QueryException::class);
        DB::table('analysis_runs')->where('id', AnalysisRun::factory()->create()->id)->update(['status' => 'DONE']);
    }

    public function test_idempotency_keys_are_unique_but_optional(): void
    {
        AnalysisRun::factory()->count(2)->create(['idempotency_key' => null]);
        $run = AnalysisRun::factory()->create();
        $run->forceFill(['idempotency_key' => $run->id])->save();

        $this->expectException(UniqueConstraintViolationException::class);
        AnalysisRun::factory()->create(['idempotency_key' => $run->id]);
    }
}
