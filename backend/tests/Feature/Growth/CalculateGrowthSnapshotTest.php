<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Enums\Growth\GrowthFailure;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisRun;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Models\GrowthObservation;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Growth\GrowthException;
use App\Services\Growth\GrowthRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Dna\CalculateDnaSnapshotTest;
use Tests\Support\AssessmentFixtures;
use Tests\Support\ChallengeFixtures;
use Tests\Support\FakeAnalyzer;
use Tests\Support\StoredResults;
use Tests\TestCase;

/**
 * App\Actions\Growth\CalculateGrowthSnapshot against PostgreSQL, from
 * assessments produced by the real DNA, competency and skill gap engines,
 * and its integration in the analysis job and growth:calculate.
 */
final class CalculateGrowthSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logs = [];

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Growth never uses AI.
        $this->app->bind(AiProvider::class, fn () => throw new RuntimeException('Growth must never use the AI provider.'));
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
        $this->project = Project::factory()->create();
    }

    private function calculate(string $skillGapSnapshotId): GrowthSnapshot
    {
        return app(CalculateGrowthSnapshot::class)->handle($skillGapSnapshotId)->snapshot;
    }

    /**
     * An assessment of the project one hour after the previous one.
     */
    private function assess(string $kind = 'oneGap', ?Project $project = null): SkillGapSnapshot
    {
        $this->travel(1)->hours();

        return $kind === 'manyGaps' ? ChallengeFixtures::manyGaps($project ?? $this->project) : ChallengeFixtures::oneGap($project ?? $this->project);
    }

    /**
     * @return array<string, GrowthObservation>
     */
    private function observations(GrowthSnapshot $snapshot): array
    {
        return $snapshot->observations()->get()->keyBy(fn (GrowthObservation $o): string => $o->metric_type->value.':'.$o->metric_key)->all();
    }

    /**
     * @return list<string>
     */
    private function logged(string $message): array
    {
        return array_values(array_map(fn (MessageLogged $l): string => $l->level, array_filter($this->logs, fn (MessageLogged $l): bool => $l->message === $message)));
    }

    public function test_a_first_assessment_establishes_no_baseline_and_no_growth(): void
    {
        $gaps = $this->assess();

        $snapshot = $this->calculate($gaps->id);

        $this->assertSame('NOT_ESTABLISHED', $snapshot->status->value);
        $this->assertNull($snapshot->previous_skill_gap_snapshot_id);
        $this->assertNull($snapshot->previous_assessed_at);
        $this->assertNull($snapshot->previous_versions);
        $this->assertSame([], $snapshot->differences);
        $this->assertSame(0, $snapshot->observations()->count(), 'no synthetic baseline, no assumed zero');
        $this->assertSame(0, $snapshot->summary['observations']);
    }

    public function test_a_second_assessment_is_compared_with_the_first(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();

        $snapshot = $this->calculate($second->id);

        $this->assertSame('COMPARED', $snapshot->status->value);
        // Provenance: both assessments' full lineage, times and versions.
        $this->assertSame(
            [$second->id, $second->competency_snapshot_id, $second->dna_snapshot_id, $second->analysis_run_id, $second->source_snapshot_id, $second->project_id, $second->user_id],
            [$snapshot->skill_gap_snapshot_id, $snapshot->competency_snapshot_id, $snapshot->dna_snapshot_id, $snapshot->analysis_run_id, $snapshot->source_snapshot_id, $snapshot->project_id, $snapshot->user_id],
        );
        $this->assertSame(
            [$first->id, $first->competency_snapshot_id, $first->dna_snapshot_id, $first->analysis_run_id, $first->source_snapshot_id],
            [$snapshot->previous_skill_gap_snapshot_id, $snapshot->previous_competency_snapshot_id, $snapshot->previous_dna_snapshot_id, $snapshot->previous_analysis_run_id, $snapshot->previous_source_snapshot_id],
        );
        $this->assertTrue($snapshot->assessed_at->equalTo(AnalysisRun::query()->findOrFail($second->analysis_run_id)->completed_at));
        $this->assertTrue($snapshot->previous_assessed_at?->equalTo(AnalysisRun::query()->findOrFail($first->analysis_run_id)->completed_at));
        $this->assertSame(['1.0.0', GrowthRules::v1_0_0()->fingerprint()], [$snapshot->rules_version, $snapshot->rules_fingerprint]);
        $this->assertSame($snapshot->versions, $snapshot->previous_versions);
        $this->assertSame(['dna_scoring_version', 'dna_specification_fingerprint', 'metrics_version', 'competency_version', 'competency_specification_fingerprint', 'skill_gap_version', 'skill_gap_specification_fingerprint', 'target_profile', 'target_profile_version'], array_keys($snapshot->versions));

        $o = $this->observations($snapshot);
        $this->assertSame(['READY', 'READY', '0.3050', '0.8050', '0.5000', 'IMPROVED'], [$o['DNA:OVERALL']->previous_state, $o['DNA:OVERALL']->current_state, $o['DNA:OVERALL']->previous_value, $o['DNA:OVERALL']->current_value, $o['DNA:OVERALL']->delta, $o['DNA:OVERALL']->status->value]);
        foreach (['FUNCTION_DESIGN', 'COMPLEXITY_MANAGEMENT', 'TYPE_STRUCTURE'] as $key) {
            $this->assertSame(['GAP', 'NO_GAP', 'IMPROVED', 'LOWER'], [$o["SKILL_GAP:{$key}"]->previous_state, $o["SKILL_GAP:{$key}"]->current_state, $o["SKILL_GAP:{$key}"]->status->value, $o["SKILL_GAP:{$key}"]->better], $key);
            $this->assertSame('UP', $o["COMPETENCY:{$key}"]->level_change, $key);
        }
        $this->assertSame(['GAP', 'GAP', '0.3000', '0.3000', '0.0000', 'UNCHANGED'], [$o['SKILL_GAP:CODE_HYGIENE']->previous_state, $o['SKILL_GAP:CODE_HYGIENE']->current_state, $o['SKILL_GAP:CODE_HYGIENE']->previous_value, $o['SKILL_GAP:CODE_HYGIENE']->current_value, $o['SKILL_GAP:CODE_HYGIENE']->delta, $o['SKILL_GAP:CODE_HYGIENE']->status->value]);
        $this->assertSame(range(1, count($o)), $snapshot->observations()->pluck('position')->all());
        $this->assertSame(count($o), $snapshot->summary['observations']);
        $this->assertSame(3, $snapshot->summary['level_changes']['UP']);
    }

    /**
     * The values are read, never recalculated: they equal the stored ones.
     */
    public function test_values_are_the_persisted_assessment_values(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();

        $o = $this->observations($this->calculate($second->id));

        $this->assertSame(DnaSnapshot::query()->findOrFail($first->dna_snapshot_id)->overall_score, $o['DNA:OVERALL']->previous_value);
        $this->assertSame(DnaSnapshot::query()->findOrFail($second->dna_snapshot_id)->dimensions['COMPLEXITY']['score'], $o['DNA:COMPLEXITY']->current_value);
        $competency = collect(CompetencySnapshot::query()->findOrFail($second->competency_snapshot_id)->competencies)->keyBy('key');
        $this->assertSame([$competency['FUNCTION_DESIGN']['score'], $competency['FUNCTION_DESIGN']['level']], [$o['COMPETENCY:FUNCTION_DESIGN']->current_value, $o['COMPETENCY:FUNCTION_DESIGN']->current_level]);
        $this->assertSame($first->results()->where('competency_key', 'FUNCTION_DESIGN')->value('raw_gap'), $o['SKILL_GAP:FUNCTION_DESIGN']->previous_value);
    }

    /**
     * The baseline is the immediately preceding assessment, never the oldest
     * and never a later one, whatever order growth is calculated in.
     */
    public function test_the_baseline_is_the_immediately_preceding_assessment(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();
        $third = $this->assess('manyGaps');

        $thirdGrowth = $this->calculate($third->id);
        $secondGrowth = $this->calculate($second->id);
        $firstGrowth = $this->calculate($first->id);

        $this->assertSame($second->id, $thirdGrowth->previous_skill_gap_snapshot_id);
        $this->assertSame($first->id, $secondGrowth->previous_skill_gap_snapshot_id);
        $this->assertNull($firstGrowth->previous_skill_gap_snapshot_id);
        $this->assertSame('REGRESSED', $this->observations($thirdGrowth)['SKILL_GAP:FUNCTION_DESIGN']->status->value);
        $this->assertSame('IMPROVED', $this->observations($secondGrowth)['SKILL_GAP:FUNCTION_DESIGN']->status->value);
    }

    public function test_the_order_is_analysis_completion_not_creation(): void
    {
        $older = $this->assess('manyGaps');
        $newer = $this->assess();
        // The older analysis finished last.
        DB::table('analysis_runs')->where('id', $older->analysis_run_id)->update(['completed_at' => now()->addHour()]);

        $this->assertSame($newer->id, $this->calculate($older->id)->previous_skill_gap_snapshot_id);
        $this->assertNull($this->calculate($newer->id)->previous_skill_gap_snapshot_id);
    }

    public function test_among_earlier_assessments_the_latest_completed_is_the_baseline(): void
    {
        $a = $this->assess('manyGaps');
        $b = $this->assess();
        $c = $this->assess();
        // A was created first but its analysis completed between B's and C's.
        DB::table('analysis_runs')->where('id', $a->analysis_run_id)->update(['completed_at' => now()->subMinutes(30)]);

        $this->assertSame($a->id, $this->calculate($c->id)->previous_skill_gap_snapshot_id);
        $this->assertSame($b->id, $this->calculate($a->id)->previous_skill_gap_snapshot_id);
    }

    public function test_other_projects_and_users_are_never_a_baseline(): void
    {
        $this->assess('manyGaps', Project::factory()->create());
        $this->assess('manyGaps', Project::factory()->for($this->project->user)->create());
        $mine = $this->assess();

        $this->assertSame('NOT_ESTABLISHED', $this->calculate($mine->id)->status->value);
    }

    public function test_an_unsuccessful_run_is_never_an_assessment(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();
        // Defence in depth: a SUCCEEDED run never changes state, but the rule holds anyway.
        DB::table('analysis_runs')->where('id', $first->analysis_run_id)->update(['status' => AnalysisRunStatus::Failed->value, 'failed_at' => now(), 'failure_code' => 'TEST', 'result_hash' => null]);

        $this->assertSame('NOT_ESTABLISHED', $this->calculate($second->id)->status->value);
        try {
            $this->calculate($first->id);
            $this->fail('An unsuccessful run has no growth.');
        } catch (GrowthException $e) {
            $this->assertSame(GrowthFailure::AssessmentIncomplete, $e->failure);
        }
    }

    public function test_different_versions_are_incomparable_and_show_no_delta(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();
        DB::table('competency_snapshots')->where('id', $first->competency_snapshot_id)->update(['specification_fingerprint' => str_repeat('a', 64)]);

        $snapshot = $this->calculate($second->id);

        $this->assertSame('INCOMPARABLE', $snapshot->status->value);
        $this->assertSame(['competency_specification_fingerprint'], $snapshot->differences);
        $this->assertSame($first->id, $snapshot->previous_skill_gap_snapshot_id, 'the baseline is recorded, not skipped');
        $this->assertSame(str_repeat('a', 64), $snapshot->previous_versions['competency_specification_fingerprint']);
        $this->assertSame(0, $snapshot->observations()->count());
        $this->assertSame(0, $snapshot->summary['observations']);
    }

    public function test_an_unchanged_reanalysis_is_unchanged(): void
    {
        $this->assess();
        $snapshot = $this->calculate($this->assess()->id);

        $statuses = $snapshot->observations()->pluck('status')->map(fn ($s) => $s->value)->unique()->values()->all();
        $this->assertSame(['UNCHANGED'], $statuses, 'no meaningful changes');
        $this->assertSame(0, $snapshot->summary['level_changes']['UP'] + $snapshot->summary['level_changes']['DOWN']);
    }

    public function test_insufficient_data_is_never_regression(): void
    {
        $this->assess();
        $this->travel(1)->hours();
        $thin = AssessmentFixtures::skillGaps($this->project, fn ($r) => StoredResults::set($r, ['files_analyzable' => 1, 'files_parsed' => 1, 'functions_total' => 1]));

        $snapshot = $this->calculate($thin->id);

        $this->assertSame('COMPARED', $snapshot->status->value);
        $statuses = $snapshot->observations()->pluck('status')->map(fn ($s) => $s->value)->all();
        $this->assertNotContains('REGRESSED', $statuses);
        $this->assertContains('INSUFFICIENT_EVIDENCE', $statuses);
    }

    public function test_calculation_is_idempotent_and_reads_no_learning_activity_and_changes_no_assessment(): void
    {
        $this->assess('manyGaps');
        $second = $this->assess();
        $tables = ['analysis_runs', 'dna_snapshots', 'competency_snapshots', 'skill_gap_snapshots', 'skill_gap_results'];
        $before = array_map(fn (string $t) => DB::table($t)->orderBy('id')->get()->all(), array_combine($tables, $tables));

        $first = app(CalculateGrowthSnapshot::class)->handle($second->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $again = app(CalculateGrowthSnapshot::class)->handle($second->id);
        $replay = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        // A replay locks the assessment and reads the stored snapshot; it never compares or inserts again.
        $this->assertCount(2, $replay, implode("\n", $replay));
        $this->assertStringContainsString('for update', $replay[0]);
        $this->assertStringContainsString('from "growth_snapshots"', $replay[1]);

        $this->assertTrue($first->created);
        $this->assertFalse($again->created);
        $this->assertSame($first->snapshot->id, $again->snapshot->id);
        $this->assertSame(1, GrowthSnapshot::query()->count());
        $this->assertEquals($before, array_map(fn (string $t) => DB::table($t)->orderBy('id')->get()->all(), array_combine($tables, $tables)));
    }

    public function test_an_unknown_assessment_fails(): void
    {
        $this->expectExceptionObject(new GrowthException(GrowthFailure::SkillGapSnapshotNotFound));
        $this->calculate(strtolower((string) Str::ulid()));
    }

    public function test_the_analysis_job_tracks_growth_after_skill_gaps(): void
    {
        FakeAnalyzer::configure();
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r, CalculateDnaSnapshotTest::rateable(...))]);
        $source = SourceSnapshot::factory()->for($this->project)->create();
        $runs = [];
        foreach ([1, 2] as $i) {
            $this->travel(1)->hours();
            $runs[] = $run = AnalysisRun::factory()->for($source)->create(['result_type' => AnalysisResultType::StaticAnalysis]);
            app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);
        }

        $statuses = GrowthSnapshot::query()->orderBy('assessed_at')->pluck('status')->map(fn ($s) => $s->value)->all();
        $this->assertSame(['NOT_ESTABLISHED', 'COMPARED'], $statuses);
        $this->assertSame($runs[1]->id, GrowthSnapshot::query()->where('status', 'COMPARED')->value('analysis_run_id'));
        $this->assertSame(['info', 'info'], $this->logged('growth.calculated'));
        Http::assertSentCount(2);
    }

    public function test_a_growth_failure_never_fails_the_analysis_or_its_assessment_and_can_be_retried(): void
    {
        FakeAnalyzer::configure();
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r, CalculateDnaSnapshotTest::rateable(...))]);
        $run = AnalysisRun::factory()->create(['result_type' => AnalysisResultType::StaticAnalysis]);
        config(['codedna.growth.rules_version' => '9.9.9']);

        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->refresh()->status);
        $gaps = $run->dnaSnapshots()->sole()->competencySnapshots()->sole()->skillGapSnapshots()->sole();
        $this->assertSame(0, GrowthSnapshot::query()->count());
        $this->assertSame(['error'], $this->logged('growth.failed'));

        config(['codedna.growth.rules_version' => '1.0.0']);
        $this->artisan('growth:calculate', ['--missing' => true])
            ->expectsOutputToContain("{$gaps->id} created NOT_ESTABLISHED")
            ->expectsOutputToContain('Growth rules 1.0.0: 1 assessment(s), 0 failed.')
            ->assertSuccessful();
        $this->artisan('growth:calculate', ['--missing' => true])->expectsOutputToContain('0 assessment(s)')->assertSuccessful();
        $this->artisan('growth:calculate', ['skill_gap_snapshot' => $gaps->id])->expectsOutputToContain('exists NOT_ESTABLISHED')->assertSuccessful();
        $this->artisan('growth:calculate', ['skill_gap_snapshot' => strtolower((string) Str::ulid())])->expectsOutputToContain('failed SKILL_GAP_SNAPSHOT_NOT_FOUND')->assertFailed();
        $this->artisan('growth:calculate')->assertExitCode(2);
        $this->artisan('growth:calculate', ['skill_gap_snapshot' => $gaps->id, '--missing' => true])->assertExitCode(2);
        $this->assertSame(1, GrowthSnapshot::query()->count());
    }

    public function test_missing_backfills_oldest_first_so_each_assessment_finds_its_baseline(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();

        $this->assertSame(0, Artisan::call('growth:calculate', ['--missing' => true]));

        $this->assertSame([
            "{$first->id} created NOT_ESTABLISHED",
            "{$second->id} created COMPARED",
            'Growth rules 1.0.0: 2 assessment(s), 0 failed.',
        ], array_values(array_filter(array_map('trim', explode("\n", Artisan::output())))));
    }

    public function test_growth_is_owned_by_the_assessment_owner(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $snapshot = $this->calculate($this->assess(project: $project)->id);

        $this->assertSame([$owner->id, $project->id], [$snapshot->user_id, $snapshot->project_id]);
    }
}
