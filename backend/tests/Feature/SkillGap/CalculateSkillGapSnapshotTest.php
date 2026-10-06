<?php

declare(strict_types=1);

namespace Tests\Feature\SkillGap;

use App\Actions\Competency\CalculateCompetencyMatrix;
use App\Actions\Dna\CalculateDnaSnapshot;
use App\Actions\SkillGap\CalculateSkillGapSnapshot;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Enums\SkillGap\SkillGapFailure;
use App\Enums\SkillGap\SkillGapSnapshotStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisRun;
use App\Models\CompetencySnapshot;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use App\Services\SkillGap\SkillGapException;
use App\Services\SkillGap\SkillGapSpecification;
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
 * App\Actions\SkillGap\CalculateSkillGapSnapshot against PostgreSQL, from
 * competency snapshots produced by the real DNA and competency engines, and
 * its integration in the analysis job and skill-gap:calculate.
 */
final class CalculateSkillGapSnapshotTest extends TestCase
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
    private function competency(?callable $mutate = null): CompetencySnapshot
    {
        $run = StoredResults::succeededRun('static_analysis', $mutate ?? CalculateDnaSnapshotTest::rateable(...));
        $dna = app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;

        return app(CalculateCompetencyMatrix::class)->handle($dna->id)->snapshot;
    }

    private function calculate(string $competencySnapshotId): SkillGapSnapshot
    {
        return app(CalculateSkillGapSnapshot::class)->handle($competencySnapshotId)->snapshot;
    }

    /**
     * @return list<string>
     */
    private function logged(string $message): array
    {
        return array_values(array_map(fn (MessageLogged $l): string => $l->level, array_filter($this->logs, fn (MessageLogged $l): bool => $l->message === $message)));
    }

    private function assertFailure(SkillGapFailure $failure, string $id): void
    {
        try {
            $this->calculate($id);
            $this->fail("Expected {$failure->value}");
        } catch (SkillGapException $e) {
            $this->assertSame($failure, $e->failure);
            $this->assertStringNotContainsString($id, $e->getMessage());
        }
    }

    public function test_a_competency_snapshot_gets_a_skill_gap_snapshot_with_full_lineage(): void
    {
        $competency = $this->competency();

        $calculated = app(CalculateSkillGapSnapshot::class)->handle($competency->id);
        $snapshot = SkillGapSnapshot::query()->with('results')->findOrFail($calculated->snapshot->id);

        $this->assertTrue($calculated->created);
        $this->assertSame(SkillGapSnapshotStatus::GapsIdentified, $snapshot->status);
        $this->assertSame(
            [$competency->id, $competency->dna_snapshot_id, $competency->analysis_run_id, $competency->source_snapshot_id, $competency->project_id, $competency->user_id],
            [$snapshot->competency_snapshot_id, $snapshot->dna_snapshot_id, $snapshot->analysis_run_id, $snapshot->source_snapshot_id, $snapshot->project_id, $snapshot->user_id],
        );
        $this->assertSame(['1.0.0', 'ENGINEERING_STANDARD', '1.0.0', '1.0.0', '1.0.0'], [
            $snapshot->skill_gap_version, $snapshot->target_profile, $snapshot->target_profile_version, $snapshot->competency_version, $snapshot->dna_scoring_version,
        ]);
        $this->assertSame(SkillGapSpecification::v1_0_0()->fingerprint(), $snapshot->specification_fingerprint);
        $this->assertTrue($competency->skillGapSnapshots()->sole()->is($snapshot));

        $rows = $snapshot->results;
        $this->assertSame(['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE', 'CODE_HYGIENE'], $rows->pluck('competency_key')->all());
        $this->assertSame([0, 1, 2, 3], $rows->pluck('position')->all());
        $hygiene = $rows[3];
        $this->assertSame(['GAP', '0.6000', '0.9000', '0.3000', true, 'HIGH', false, '0.9000', 'DEVELOPING', 'ASSESSED'], [
            $hygiene->status->value, $hygiene->current_score, $hygiene->target_score, $hygiene->raw_gap, $hygiene->material_gap,
            $hygiene->priority?->value, $hygiene->priority_capped, $hygiene->evidence_quality, $hygiene->current_level, $hygiene->competency_status,
        ]);
        $this->assertSame(['NO_GAP', '0.7500', '0.0000', false, null], [
            $rows[0]->status->value, $rows[0]->current_score, $rows[0]->raw_gap, $rows[0]->material_gap, $rows[0]->priority,
        ]);
        $this->assertEquals([['source' => 'CODE_HYGIENE.syntax_error_share', 'status' => 'AVAILABLE', 'value' => '0.1000', 'score' => '0.6000']], $hygiene->evidence['evidence']);
        $this->assertSame([$competency->user_id, $competency->project_id], [$hygiene->user_id, $hygiene->project_id]);
        $this->assertEquals([
            'skill_gap_version' => '1.0.0',
            'specification_fingerprint' => $snapshot->specification_fingerprint,
            'target_profile' => 'ENGINEERING_STANDARD',
            'target_profile_version' => '1.0.0',
            'competency_snapshot_id' => $competency->id,
            'competency_version' => '1.0.0',
            'competency_specification_fingerprint' => $competency->specification_fingerprint,
            'competency_status' => 'ASSESSED',
            'dna_snapshot_id' => $competency->dna_snapshot_id,
            'dna_scoring_version' => '1.0.0',
            'analysis_run_id' => $competency->analysis_run_id,
            'source_snapshot_id' => $competency->source_snapshot_id,
            'result_hash' => $competency->provenance['result_hash'],
            'languages' => ['javascript', 'php', 'python'],
        ], $snapshot->provenance);
        // JSONB does not keep key order; the API resource restores a fixed order.
        $this->assertEquals([
            'competencies' => 4, 'material_gaps' => 1,
            'statuses' => ['GAP' => 1, 'NO_GAP' => 3, 'INSUFFICIENT_EVIDENCE' => 0, 'UNSUPPORTED' => 0, 'MISSING' => 0, 'NOT_TARGETED' => 0],
            'priorities' => ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 1],
        ], $snapshot->summary);
    }

    public function test_insufficient_competency_evidence_produces_no_gaps(): void
    {
        $competency = app(CalculateCompetencyMatrix::class)->handle(app(CalculateDnaSnapshot::class)->handle(StoredResults::succeededRun()->id)->snapshot->id)->snapshot;

        $rows = $this->calculate($competency->id)->results()->get();

        $this->assertSame(['INSUFFICIENT_EVIDENCE', 'INSUFFICIENT_EVIDENCE', 'INSUFFICIENT_EVIDENCE', 'GAP'], $rows->map(fn (SkillGapResult $r): string => $r->status->value)->all());
        $this->assertSame([null, null, null, '0.9000'], $rows->pluck('raw_gap')->all());
        $this->assertSame([null, null, null, 'MEDIUM'], $rows->map(fn (SkillGapResult $r): ?string => $r->priority?->value)->all());
        $this->assertTrue($rows[3]->priority_capped, 'evidence quality 0.5984 is below 0.60');
    }

    public function test_calculation_is_idempotent_and_returns_the_existing_snapshot(): void
    {
        $competency = $this->competency();

        $first = app(CalculateSkillGapSnapshot::class)->handle($competency->id);
        $second = app(CalculateSkillGapSnapshot::class)->handle($competency->id);
        // An existing snapshot is returned without reading the competency results again.
        DB::table('competency_snapshots')->where('id', $competency->id)->update(['competencies' => '[{"key": "broken"}]']);
        $third = app(CalculateSkillGapSnapshot::class)->handle($competency->id);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertFalse($third->created);
        $this->assertTrue($first->snapshot->is($second->snapshot) && $first->snapshot->is($third->snapshot));
        $this->assertSame([1, 4], [SkillGapSnapshot::query()->count(), SkillGapResult::query()->count()]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('skill_gap_snapshots')->insert([
            ...collect(DB::table('skill_gap_snapshots')->first())->except('id')->all(),
            'id' => strtolower((string) Str::ulid()),
        ]);
    }

    public function test_snapshots_and_results_are_immutable(): void
    {
        $snapshot = $this->calculate($this->competency()->id);
        $result = $snapshot->results()->firstOrFail();

        foreach ([
            fn () => $snapshot->forceFill(['status' => 'INSUFFICIENT_DATA'])->save(),
            fn () => $snapshot->delete(),
            fn () => $result->forceFill(['raw_gap' => '0.1234'])->save(),
            fn () => $result->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Skill gap history must not change.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('GAPS_IDENTIFIED', DB::table('skill_gap_snapshots')->where('id', $snapshot->id)->value('status'));
    }

    public function test_the_database_enforces_the_gap_formula_and_status_consistency(): void
    {
        $snapshot = $this->calculate($this->competency()->id);
        $gapRow = collect(DB::table('skill_gap_results')->where('competency_key', 'CODE_HYGIENE')->first())->except('id')->all();

        foreach ([
            ['raw_gap' => '0.2000'],                                       // not target − current
            ['raw_gap' => null],                                           // a measured gap without raw gap
            ['status' => 'NO_GAP'],                                        // material, but not GAP
            ['priority' => null, 'priority_capped' => null],               // GAP without priority
            ['status' => 'INSUFFICIENT_EVIDENCE'],                         // unmeasured with scores
            ['status' => 'NOT_TARGETED'],                                  // untargeted with a target
            ['priority' => 'URGENT'],
            ['current_score' => '1.5000'],
            ['skill_gap_snapshot_id' => strtolower((string) Str::ulid())],
            ['user_id' => $this->competency()->user_id],                   // another owner than the snapshot's
        ] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('skill_gap_results')->insert([
                    ...$gapRow, 'competency_key' => 'X_TEST', 'position' => 99, 'id' => strtolower((string) Str::ulid()), ...$invalid,
                ]));
                $this->fail('Accepted '.json_encode($invalid));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(4, $snapshot->results()->count());
    }

    public function test_unknown_or_incompatible_competency_snapshots_are_rejected(): void
    {
        $this->assertFailure(SkillGapFailure::CompetencySnapshotNotFound, strtolower((string) Str::ulid()));

        $other = $this->competency();
        DB::table('competency_snapshots')->where('id', $other->id)->update(['competency_version' => '0.9.0']);
        $this->assertFailure(SkillGapFailure::CompetencyVersionUnsupported, $other->id);

        $tampered = $this->competency();
        DB::table('competency_snapshots')->where('id', $tampered->id)->update(['specification_fingerprint' => str_repeat('a', 64)]);
        $this->assertFailure(SkillGapFailure::CompetencySnapshotInvalid, $tampered->id);

        $this->assertSame(0, SkillGapSnapshot::query()->count());

        config(['codedna.skill_gap.version' => '2.0.0']);
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($this->competency()->id);
    }

    public function test_the_analysis_job_derives_skill_gaps_after_competencies_without_network_beyond_the_analyzer(): void
    {
        FakeAnalyzer::configure();
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r, CalculateDnaSnapshotTest::rateable(...))]);
        $run = AnalysisRun::factory()->create(['result_type' => AnalysisResultType::StaticAnalysis]);

        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);

        $competency = $run->dnaSnapshots()->sole()->competencySnapshots()->sole();
        $snapshot = $competency->skillGapSnapshots()->sole();
        $this->assertSame('GAPS_IDENTIFIED', $snapshot->status->value);
        $this->assertSame(['info'], $this->logged('skill_gap.calculated'));
        Http::assertSentCount(1);
    }

    public function test_a_skill_gap_failure_never_fails_the_analysis_dna_or_competencies_and_can_be_retried(): void
    {
        FakeAnalyzer::configure();
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r, CalculateDnaSnapshotTest::rateable(...))]);
        $run = AnalysisRun::factory()->create(['result_type' => AnalysisResultType::StaticAnalysis]);
        config(['codedna.skill_gap.version' => '9.9.9']);

        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->refresh()->status);
        $competency = $run->dnaSnapshots()->sole()->competencySnapshots()->sole();
        $this->assertSame(0, SkillGapSnapshot::query()->count());
        $this->assertSame(['error'], $this->logged('skill_gap.failed'));

        config(['codedna.skill_gap.version' => '1.0.0']);
        $this->artisan('skill-gap:calculate', ['--missing' => true])
            ->expectsOutputToContain("{$competency->id} created GAPS_IDENTIFIED")
            ->expectsOutputToContain('Skill gap version 1.0.0 (ENGINEERING_STANDARD): 1 competency snapshot(s), 0 failed.')
            ->assertSuccessful();
        $this->artisan('skill-gap:calculate', ['--missing' => true])->expectsOutputToContain('0 competency snapshot(s)')->assertSuccessful();
        $this->artisan('skill-gap:calculate', ['competency_snapshot' => $competency->id])->expectsOutputToContain('exists GAPS_IDENTIFIED')->assertSuccessful();
        $this->artisan('skill-gap:calculate', ['competency_snapshot' => strtolower((string) Str::ulid())])->expectsOutputToContain('failed COMPETENCY_SNAPSHOT_NOT_FOUND')->assertFailed();
        $this->artisan('skill-gap:calculate')->assertExitCode(2);
        $this->assertSame(1, SkillGapSnapshot::query()->count());
    }
}
