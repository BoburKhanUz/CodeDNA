<?php

declare(strict_types=1);

namespace Tests\Feature\Competency;

use App\Actions\Competency\CalculateCompetencyMatrix;
use App\Actions\Dna\CalculateDnaSnapshot;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Enums\Competency\CompetencyFailure;
use App\Enums\Competency\CompetencySnapshotStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisRun;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Services\Competency\CompetencyEngine;
use App\Services\Competency\CompetencyException;
use App\Services\Competency\CompetencySpecification;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use Tests\Feature\Dna\CalculateDnaSnapshotTest;
use Tests\Support\FakeAnalyzer;
use Tests\Support\StoredResults;
use Tests\TestCase;

/**
 * App\Actions\Competency\CalculateCompetencyMatrix against PostgreSQL, from
 * DNA snapshots created by the real scoring engine, and its integration in
 * the analysis job and the competency:calculate command.
 */
final class CalculateCompetencyMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
    }

    /**
     * @param  (callable(stdClass): void)|null  $mutate
     */
    private function dna(?callable $mutate = null): DnaSnapshot
    {
        $run = StoredResults::succeededRun('static_analysis', $mutate ?? CalculateDnaSnapshotTest::rateable(...));

        return app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;
    }

    private function calculate(string $dnaSnapshotId): CompetencySnapshot
    {
        return app(CalculateCompetencyMatrix::class)->handle($dnaSnapshotId)->snapshot;
    }

    /**
     * @return list<string>
     */
    private function logged(string $message): array
    {
        return array_values(array_map(fn (MessageLogged $l): string => $l->level, array_filter($this->logs, fn (MessageLogged $l): bool => $l->message === $message)));
    }

    public function test_a_dna_snapshot_gets_an_immutable_competency_snapshot_with_its_lineage(): void
    {
        $dna = $this->dna();

        $calculated = app(CalculateCompetencyMatrix::class)->handle($dna->id);
        $snapshot = CompetencySnapshot::query()->findOrFail($calculated->snapshot->id);

        $this->assertTrue($calculated->created);
        $this->assertSame(CompetencySnapshotStatus::Assessed, $snapshot->status);
        $this->assertSame(
            [$dna->id, $dna->analysis_run_id, $dna->source_snapshot_id, $dna->project_id, $dna->user_id],
            [$snapshot->dna_snapshot_id, $snapshot->analysis_run_id, $snapshot->source_snapshot_id, $snapshot->project_id, $snapshot->user_id],
        );
        $this->assertSame(['1.0.0', '1.0.0'], [$snapshot->competency_version, $snapshot->dna_scoring_version]);
        $this->assertSame(CompetencySpecification::v1_0_0()->fingerprint(), $snapshot->specification_fingerprint);
        $this->assertTrue($snapshot->dnaSnapshot->is($dna));
        $this->assertTrue($dna->competencySnapshots()->sole()->is($snapshot));
        $this->assertSame(['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE', 'CODE_HYGIENE'], array_column($snapshot->competencies, 'key'));
        $this->assertSame(['0.7500', '0.9000', '1.0000', '0.6000'], array_column($snapshot->competencies, 'score'));
        $this->assertSame(['ESTABLISHED', 'STRONG', 'STRONG', 'DEVELOPING'], array_column($snapshot->competencies, 'level'));
        $this->assertEquals([
            'competency_version' => '1.0.0',
            'specification_fingerprint' => $snapshot->specification_fingerprint,
            'dna_snapshot_id' => $dna->id,
            'dna_scoring_version' => '1.0.0',
            'dna_specification_fingerprint' => $dna->evidence['specification_fingerprint'],
            'dna_status' => 'READY',
            'dna_overall_score' => '0.8050',
            'dna_data_quality' => '0.9000',
            'analysis_run_id' => $dna->analysis_run_id,
            'source_snapshot_id' => $dna->source_snapshot_id,
            'result_hash' => $dna->result_hash,
            'metrics_version' => '1.0',
            // The captured result measured JavaScript, PHP and Python.
            'languages' => ['javascript', 'php', 'python'],
        ], $snapshot->provenance);
        // The matrix is exactly what the engine computes from the stored DNA.
        $this->assertEquals(
            (new CompetencyEngine)->assess($dna->dimensions, $dna->evidence ?? [], '1.0.0', ['javascript', 'php', 'python'], CompetencySpecification::v1_0_0())->competencies,
            $snapshot->competencies,
        );
    }

    public function test_an_insufficient_data_dna_snapshot_still_gets_a_matrix(): void
    {
        $dna = app(CalculateDnaSnapshot::class)->handle(StoredResults::succeededRun()->id)->snapshot;

        $snapshot = $this->calculate($dna->id)->refresh();

        $this->assertSame(CompetencySnapshotStatus::Assessed, $snapshot->status, 'CODE_HYGIENE is assessable');
        $this->assertSame(['INSUFFICIENT_EVIDENCE', 'INSUFFICIENT_EVIDENCE', 'INSUFFICIENT_EVIDENCE', 'ASSESSED'], array_column($snapshot->competencies, 'status'));
        $this->assertSame([null, null, null, '0.0000'], array_column($snapshot->competencies, 'score'));
    }

    public function test_calculation_is_idempotent_and_returns_the_existing_snapshot(): void
    {
        $dna = $this->dna();

        $first = app(CalculateCompetencyMatrix::class)->handle($dna->id);
        $second = app(CalculateCompetencyMatrix::class)->handle($dna->id);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertTrue($first->snapshot->is($second->snapshot));
        $this->assertSame(1, CompetencySnapshot::query()->count());

        // An existing snapshot is returned as is: the DNA is not read or assessed again.
        DB::table('dna_snapshots')->where('id', $dna->id)->update(['dimensions' => '{"COMPLEXITY": {"components": {"mean_cyclomatic_complexity": "invalid"}}}']);
        $third = app(CalculateCompetencyMatrix::class)->handle($dna->id);
        $this->assertFalse($third->created);
        $this->assertTrue($first->snapshot->is($third->snapshot));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('competency_snapshots')->insert([
            ...collect(DB::table('competency_snapshots')->first())->except('id')->all(),
            'id' => strtolower((string) Str::ulid()),
        ]);
    }

    public function test_competency_snapshots_cannot_be_changed_or_deleted(): void
    {
        $snapshot = $this->calculate($this->dna()->id);

        foreach ([
            fn () => $snapshot->forceFill(['status' => 'INSUFFICIENT_DATA'])->save(),
            fn () => $snapshot->forceFill(['competencies' => [['key' => 'X']]])->save(),
            fn () => $snapshot->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Competency snapshots must not change.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('ASSESSED', DB::table('competency_snapshots')->where('id', $snapshot->id)->value('status'));
    }

    public function test_the_lineage_cannot_disagree_with_the_dna_snapshot(): void
    {
        $snapshot = $this->calculate($this->dna()->id);
        $other = $this->dna();
        $row = collect(DB::table('competency_snapshots')->first())->except('id')->all();

        foreach ([
            ['analysis_run_id' => $other->analysis_run_id],
            ['source_snapshot_id' => $other->source_snapshot_id],
            ['project_id' => $other->project_id, 'user_id' => $other->user_id],
            ['specification_fingerprint' => 'not-a-hash'],
            ['status' => 'GOOD'],
            ['competencies' => '{}'],
        ] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('competency_snapshots')->insert([
                    ...$row, 'competency_version' => '9.9.9', 'id' => strtolower((string) Str::ulid()), ...$invalid,
                ]));
                $this->fail('Accepted '.json_encode($invalid));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, CompetencySnapshot::query()->count());
        $this->assertNotNull($snapshot);
    }

    public function test_unknown_and_unsupported_inputs_are_rejected(): void
    {
        try {
            $this->calculate(strtolower((string) Str::ulid()));
            $this->fail('accepted a missing DNA snapshot');
        } catch (CompetencyException $e) {
            $this->assertSame(CompetencyFailure::DnaSnapshotNotFound, $e->failure);
        }

        // A DNA snapshot of another scoring version (a pre-1.0.0 fixture record).
        $legacy = DnaSnapshot::factory()->create();
        try {
            $this->calculate($legacy->id);
            $this->fail('accepted an unsupported DNA scoring version');
        } catch (CompetencyException $e) {
            $this->assertSame(CompetencyFailure::DnaScoringVersionUnsupported, $e->failure);
            $this->assertStringNotContainsString($legacy->id, $e->getMessage());
        }

        config(['codedna.competency.version' => '2.0.0']);
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($this->dna()->id);
    }

    public function test_no_network_or_analyzer_call_is_made(): void
    {
        $dna = $this->dna();
        Http::fake();

        $this->calculate($dna->id);

        Http::assertNothingSent();
    }

    public function test_the_analysis_job_derives_the_matrix_after_scoring(): void
    {
        FakeAnalyzer::configure();
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r, CalculateDnaSnapshotTest::rateable(...))]);
        $run = AnalysisRun::factory()->create(['result_type' => AnalysisResultType::StaticAnalysis]);

        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);

        $dna = $run->dnaSnapshots()->sole();
        $snapshot = $dna->competencySnapshots()->sole();
        $this->assertSame(['0.7500', '0.9000', '1.0000', '0.6000'], array_column($snapshot->competencies, 'score'));
        $this->assertSame(['info'], $this->logged('competency.calculated'));
    }

    public function test_a_competency_failure_never_fails_the_analysis_or_the_dna_and_can_be_retried(): void
    {
        FakeAnalyzer::configure();
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r, CalculateDnaSnapshotTest::rateable(...))]);
        $run = AnalysisRun::factory()->create(['result_type' => AnalysisResultType::StaticAnalysis]);
        config(['codedna.competency.version' => '9.9.9']);

        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->refresh()->status);
        $dna = $run->dnaSnapshots()->sole();
        $this->assertSame(0, CompetencySnapshot::query()->count());
        $this->assertSame(['error'], $this->logged('competency.failed'));

        config(['codedna.competency.version' => '1.0.0']);
        $this->artisan('competency:calculate', ['--missing' => true])
            ->expectsOutputToContain("{$dna->id} created ASSESSED")
            ->expectsOutputToContain('Competency version 1.0.0: 1 DNA snapshot(s), 0 failed.')
            ->assertSuccessful();
        $this->assertSame(1, $dna->competencySnapshots()->count());
    }

    public function test_foundation_runs_produce_neither_dna_nor_competencies(): void
    {
        FakeAnalyzer::configure();
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r)]);
        $run = AnalysisRun::factory()->create(['result_type' => AnalysisResultType::Foundation]);

        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);

        $this->assertSame(0, CompetencySnapshot::query()->count());
        $this->assertSame([], $this->logged('competency.failed'));
    }

    public function test_the_command_assesses_missing_snapshots_idempotently(): void
    {
        $ready = $this->dna();
        $insufficient = app(CalculateDnaSnapshot::class)->handle(StoredResults::succeededRun()->id)->snapshot;
        DnaSnapshot::factory()->create(); // scoring version 1.0: not supported, not selected

        $this->artisan('competency:calculate', ['--missing' => true])
            ->expectsOutputToContain('Competency version 1.0.0: 2 DNA snapshot(s), 0 failed.')
            ->assertSuccessful();
        $this->artisan('competency:calculate', ['--missing' => true])->expectsOutputToContain('0 DNA snapshot(s)')->assertSuccessful();
        $this->artisan('competency:calculate', ['dna_snapshot' => $ready->id])->expectsOutputToContain("{$ready->id} exists ASSESSED")->assertSuccessful();
        $this->artisan('competency:calculate', ['dna_snapshot' => strtolower((string) Str::ulid())])->expectsOutputToContain('failed DNA_SNAPSHOT_NOT_FOUND')->assertFailed();
        $this->artisan('competency:calculate')->assertExitCode(2);

        $this->assertSame(2, CompetencySnapshot::query()->count());
        $this->assertSame(1, $insufficient->competencySnapshots()->count());
    }
}
