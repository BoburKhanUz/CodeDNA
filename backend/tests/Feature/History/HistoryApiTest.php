<?php

declare(strict_types=1);

namespace Tests\Feature\History;

use App\Actions\Competency\CalculateCompetencyMatrix;
use App\Actions\Dna\CalculateDnaSnapshot;
use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Enums\SourceType;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use App\Services\Growth\GrowthRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Dna\CalculateDnaSnapshotTest;
use Tests\Support\AssessmentFixtures;
use Tests\Support\ChallengeFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\Support\StoredResults;
use Tests\TestCase;

/**
 * Historical DNA (Phase 20): a read-only, owner-only view over the stored
 * DNA, competency, skill gap and growth snapshots. Nothing is recalculated
 * or stored, versions are never mixed, and missing values are never zero.
 * No AI (the provider throws).
 */
final class HistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = 'Historical DNA shows each code assessment exactly as it was recorded. Stored values are never recalculated or rewritten, and assessments measured with different versions are never compared. Learning activity is context only.';

    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => false, 'codedna.challenges.enabled' => true]);
        $this->app->bind(AiProvider::class, fn () => throw new RuntimeException('History must never use the AI provider.'));
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator('pass'));
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function read(string $suffix = '', ?User $as = null, ?Project $project = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->getJson('/api/v1/projects/'.($project ?? $this->project)->id."/history{$suffix}");
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function compare(string $from, string $to, ?User $as = null, ?Project $project = null): TestResponse
    {
        return $this->read("/compare?from={$from}&to={$to}", $as, $project);
    }

    /**
     * A full assessment one hour later, with its growth tracked as the analysis job does.
     *
     * @param  array<string, mixed>  $source
     * @param  (callable(SkillGapSnapshot): void)|null  $before  runs before growth is tracked
     */
    private function assess(string $kind = 'oneGap', ?Project $project = null, bool $track = true, array $source = [], ?callable $before = null): SkillGapSnapshot
    {
        $this->travel(1)->hours();
        $gaps = AssessmentFixtures::skillGaps(
            $project ?? $this->project,
            $kind === 'manyGaps' ? ChallengeFixtures::everythingBelowTarget(...) : null,
            $source,
        );
        if ($before !== null) {
            $before($gaps);
        }
        if ($track) {
            app(CalculateGrowthSnapshot::class)->handle($gaps->id);
        }

        return $gaps;
    }

    /** A DNA snapshot one hour later whose competency matrix was never calculated. */
    private function dnaOnly(?Project $project = null): DnaSnapshot
    {
        $this->travel(1)->hours();
        $source = SourceSnapshot::factory()->for($project ?? $this->project)->create();
        $run = StoredResults::succeededRun('static_analysis', CalculateDnaSnapshotTest::rateable(...), $source);

        return app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private static function by(array $list, string $field, string $value): array
    {
        foreach ($list as $item) {
            if (($item[$field] ?? null) === $value) {
                return $item;
            }
        }
        self::fail("No item with {$field} = {$value}.");
    }

    // Timeline -------------------------------------------------------------

    public function test_a_project_without_an_assessment_has_no_history(): void
    {
        $this->read()->assertOk()->assertExactJson(['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1]]);
    }

    public function test_one_assessment_is_one_point_with_its_full_lineage(): void
    {
        $gaps = $this->assess();
        $dna = DnaSnapshot::query()->findOrFail($gaps->dna_snapshot_id);

        $response = $this->read()->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $point = $response->json('data.0');

        $this->assertSame(['id', 'type', 'project_id', 'analyzed_at', 'analysis_run_id', 'layers', 'versions', 'segments', 'dna', 'competency', 'skill_gaps', 'source', 'growth'], array_keys($point));
        $this->assertSame([$dna->id, 'history_point', $this->project->id, $gaps->analysis_run_id], [$point['id'], $point['type'], $point['project_id'], $point['analysis_run_id']]);
        $this->assertSame(['dna' => 'AVAILABLE', 'competency' => 'AVAILABLE', 'skill_gaps' => 'AVAILABLE'], $point['layers']);
        $this->assertSame($dna->analysisRun->completed_at?->toIso8601ZuluString(), $point['analyzed_at']);
        $this->assertSame([$dna->overall_score, $dna->data_quality, $dna->scoring_version, $dna->evidence['specification_fingerprint'], $dna->metrics_version],
            [$point['dna']['overall_score'], $point['dna']['data_quality'], $point['dna']['scoring_version'], $point['dna']['specification_fingerprint'], $point['dna']['metrics_version']]);
        $this->assertSame([$gaps->competency_snapshot_id, '1.0.0'], [$point['competency']['snapshot_id'], $point['competency']['competency_version']]);
        $this->assertSame([$gaps->id, $gaps->skill_gap_version, $gaps->target_profile, $gaps->target_profile_version],
            [$point['skill_gaps']['snapshot_id'], $point['skill_gaps']['skill_gap_version'], $point['skill_gaps']['target_profile'], $point['skill_gaps']['target_profile_version']]);
        $this->assertSame([
            'dna_scoring_version' => $dna->scoring_version, 'dna_specification_fingerprint' => $dna->evidence['specification_fingerprint'], 'metrics_version' => $dna->metrics_version,
            'competency_version' => '1.0.0', 'competency_specification_fingerprint' => $point['competency']['specification_fingerprint'],
            'skill_gap_version' => $gaps->skill_gap_version, 'skill_gap_specification_fingerprint' => $gaps->specification_fingerprint,
            'target_profile' => $gaps->target_profile, 'target_profile_version' => $gaps->target_profile_version,
        ], $point['versions']);
        $this->assertSame($gaps->source_snapshot_id, $point['source']['snapshot_id']);

        // The first assessment's growth: no baseline, never zero.
        $growth = GrowthSnapshot::query()->sole();
        $this->assertSame([$growth->id, 'NOT_ESTABLISHED', null, []], [$point['growth']['id'], $point['growth']['status'], $point['growth']['previous_dna_snapshot_id'], $point['growth']['events']]);

        $detail = $this->read("/{$dna->id}")->assertOk()->json('data');
        $this->assertSame([self::NOTICE, null, null, null], [$detail['notice'], $detail['previous'], $detail['next'], $detail['activity']]);
        $this->assertSame($point, array_diff_key($detail, array_flip(['notice', 'previous', 'next', 'activity'])));
    }

    public function test_points_are_newest_first_by_analysis_time_and_paginated(): void
    {
        $a = $this->assess();
        $b = $this->assess();
        $c = $this->assess();
        // Analysis time decides, not the order the rows were written.
        DB::table('analysis_runs')->where('id', $b->analysis_run_id)->update(['completed_at' => now()->addHours(5), 'updated_at' => now()->addHours(5)]);

        $this->assertSame([$b->dna_snapshot_id, $c->dna_snapshot_id, $a->dna_snapshot_id], array_column($this->read()->assertOk()->json('data'), 'id'));

        $page = $this->read('?per_page=2')->assertOk();
        $this->assertSame([$b->dna_snapshot_id, $c->dna_snapshot_id], array_column($page->json('data'), 'id'));
        $this->assertSame(['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $page->json('meta'));
        $this->assertSame([$a->dna_snapshot_id], array_column($this->read('?per_page=2&page=2')->json('data'), 'id'));
        $this->assertSame([], $this->read('?per_page=2&page=3')->json('data'));

        $this->read('?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->read('?per_page=101')->assertUnprocessable();
        $this->read('?per_page=0')->assertUnprocessable();
        $this->read('?page=0')->assertUnprocessable();
    }

    public function test_the_default_page_holds_twenty_five_points(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->dnaOnly();
        }

        $page = $this->read()->assertOk();
        $this->assertCount(25, $page->json('data'));
        $this->assertSame(['current_page' => 1, 'per_page' => 25, 'total' => 26, 'last_page' => 2], $page->json('meta'));
    }

    public function test_only_successful_analyses_are_history(): void
    {
        $gaps = $this->assess();
        // Runs that never produced DNA: queued, failed and cancelled.
        foreach (['QUEUED', 'FAILED', 'CANCELLED'] as $status) {
            $source = SourceSnapshot::factory()->for($this->project)->create();
            DB::table('analysis_runs')->insert([
                'id' => strtolower((string) Str::ulid()), 'project_id' => $this->project->id, 'source_snapshot_id' => $source->id,
                'status' => $status, 'result_type' => 'static_analysis', 'created_at' => now(), 'updated_at' => now(),
                'started_at' => $status === 'QUEUED' ? null : now(), 'completed_at' => $status === 'QUEUED' ? null : now(),
                'failed_at' => $status === 'FAILED' ? now() : null, 'failure_code' => $status === 'FAILED' ? 'TEST' : null,
            ]);
        }

        $this->assertSame([$gaps->dna_snapshot_id], array_column($this->read()->json('data'), 'id'));
    }

    public function test_the_query_string_accepts_pagination_only(): void
    {
        $this->assess();

        $this->read('?score=1')->assertUnprocessable();
        $this->read('?scoring_version=2.0.0')->assertUnprocessable();
        $this->read('?page=1&per_page=10')->assertOk();
    }

    // DNA ------------------------------------------------------------------

    public function test_dimensions_are_the_stored_values_and_missing_is_never_zero(): void
    {
        $gaps = $this->assess();
        $dna = DnaSnapshot::query()->findOrFail($gaps->dna_snapshot_id);
        $point = $this->read()->json('data.0');

        $this->assertSame(['COMPLEXITY', 'STRUCTURE', 'CODE_HYGIENE'], array_column($point['dna']['dimensions'], 'dimension'));
        foreach ($point['dna']['dimensions'] as $dimension) {
            $stored = $dna->dimensions[$dimension['dimension']];
            $this->assertSame([$stored['status'], $stored['score'] ?? null, $stored['data_quality'] ?? null], [$dimension['status'], $dimension['score'], $dimension['data_quality']]);
            $this->assertSame(['dimension', 'name', 'status', 'score', 'data_quality'], array_keys($dimension));
        }

        // A dimension the snapshot does not contain, and one that was unavailable.
        $dimensions = $dna->dimensions;
        unset($dimensions['STRUCTURE']);
        $dimensions['COMPLEXITY'] = ['status' => 'UNAVAILABLE', 'score' => null, 'data_quality' => null];
        DB::table('dna_snapshots')->where('id', $dna->id)->update(['dimensions' => json_encode($dimensions)]);

        $dimensions = $this->read()->json('data.0.dna.dimensions');
        $this->assertSame(['dimension' => 'STRUCTURE', 'name' => 'Structure', 'status' => 'MISSING', 'score' => null, 'data_quality' => null], self::by($dimensions, 'dimension', 'STRUCTURE'));
        $this->assertSame(['dimension' => 'COMPLEXITY', 'name' => 'Complexity', 'status' => 'UNAVAILABLE', 'score' => null, 'data_quality' => null], self::by($dimensions, 'dimension', 'COMPLEXITY'));
        $this->assertStringNotContainsString('"score":"0.0000"', $this->read()->getContent());
    }

    public function test_each_point_keeps_its_own_historical_values(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();

        $points = $this->read()->json('data');
        $this->assertSame('0.8050', $points[0]['dna']['overall_score']);
        $this->assertSame('0.3050', $points[1]['dna']['overall_score'], 'never the current value');
        $this->assertSame(DnaSnapshot::query()->findOrFail($first->dna_snapshot_id)->overall_score, $points[1]['dna']['overall_score']);
        $this->assertSame($second->dna_snapshot_id, $points[0]['id']);
    }

    public function test_scoring_versions_segment_the_history(): void
    {
        $a = $this->assess();
        $b = $this->assess();
        $c = $this->assess(track: false, before: fn (SkillGapSnapshot $g) => DB::table('dna_snapshots')->where('id', $g->dna_snapshot_id)->update(['scoring_version' => '2.0.0']));

        [$pc, $pb, $pa] = $this->read()->json('data');
        $this->assertSame([$c->dna_snapshot_id, $b->dna_snapshot_id, $a->dna_snapshot_id], [$pc['id'], $pb['id'], $pa['id']]);
        $this->assertSame($pa['segments']['dna'], $pb['segments']['dna']);
        $this->assertNotSame($pb['segments']['dna'], $pc['segments']['dna']);
        $this->assertNotSame($pb['segments']['competency'], $pc['segments']['competency']);
        $this->assertNotSame($pb['segments']['skill_gaps'], $pc['segments']['skill_gaps']);
        $this->assertSame(['1.0.0', '1.0.0', '2.0.0'], [$pa['dna']['scoring_version'], $pb['dna']['scoring_version'], $pc['dna']['scoring_version']]);
        $this->assertSame('2.0.0', $pc['versions']['dna_scoring_version']);
    }

    public function test_competency_and_skill_gap_versions_segment_their_layers_only(): void
    {
        $this->assess();
        $b = $this->assess(track: false, before: fn (SkillGapSnapshot $g) => DB::table('competency_snapshots')->where('id', $g->competency_snapshot_id)->update(['competency_version' => '0.9.0']));
        $c = $this->assess(track: false, before: fn (SkillGapSnapshot $g) => DB::table('skill_gap_snapshots')->where('id', $g->id)->update(['target_profile_version' => '0.9.0']));

        [$pc, $pb, $pa] = $this->read()->json('data');
        $this->assertSame([$pa['segments']['dna'], $pa['segments']['dna']], [$pb['segments']['dna'], $pc['segments']['dna']]);
        $this->assertNotSame($pa['segments']['competency'], $pb['segments']['competency']);
        $this->assertSame($pa['segments']['competency'], $pc['segments']['competency']);
        $this->assertNotSame($pa['segments']['skill_gaps'], $pc['segments']['skill_gaps']);
        $this->assertSame(['0.9.0', '0.9.0'], [$pb['competency']['competency_version'], $pc['skill_gaps']['target_profile_version']]);
        $this->assertSame([$b->id, $c->id], [$pb['skill_gaps']['snapshot_id'], $pc['skill_gaps']['snapshot_id']]);
    }

    // Competencies and skill gaps --------------------------------------------

    public function test_a_missing_competency_layer_is_unavailable_not_removed(): void
    {
        $gaps = $this->assess();
        $dna = $this->dnaOnly();

        [$latest, $earlier] = $this->read()->json('data');
        $this->assertSame($dna->id, $latest['id']);
        $this->assertSame(['dna' => 'AVAILABLE', 'competency' => 'UNAVAILABLE', 'skill_gaps' => 'UNAVAILABLE'], $latest['layers']);
        $this->assertSame([null, null, null, null], [$latest['competency'], $latest['skill_gaps'], $latest['growth'], $latest['segments']['competency']]);
        $this->assertNull($latest['segments']['skill_gaps']);
        $this->assertNotNull($latest['dna']['overall_score']);
        $this->assertNull($latest['versions']['competency_version']);
        $this->assertSame($gaps->dna_snapshot_id, $earlier['id']);
    }

    public function test_a_missing_skill_gap_layer_is_unavailable(): void
    {
        $this->travel(1)->hours();
        $source = SourceSnapshot::factory()->for($this->project)->create();
        $run = StoredResults::succeededRun('static_analysis', CalculateDnaSnapshotTest::rateable(...), $source);
        $dna = app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;
        $competency = app(CalculateCompetencyMatrix::class)->handle($dna->id)->snapshot;

        $point = $this->read()->json('data.0');
        $this->assertSame(['dna' => 'AVAILABLE', 'competency' => 'AVAILABLE', 'skill_gaps' => 'UNAVAILABLE'], $point['layers']);
        $this->assertSame($competency->id, $point['competency']['snapshot_id']);
        $this->assertNull($point['skill_gaps']);
    }

    public function test_competency_scores_and_levels_are_stored_values_with_transitions(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();

        [$latest, $earlier] = $this->read()->json('data');
        foreach ([[$earlier, $first], [$latest, $second]] as [$point, $gaps]) {
            $stored = CompetencySnapshot::query()->findOrFail($gaps->competency_snapshot_id)->competencies;
            $this->assertSame(array_column($stored, 'key'), array_column($point['competency']['competencies'], 'key'));
            foreach ($stored as $i => $entry) {
                $this->assertSame([$entry['status'], $entry['score'] ?? null, $entry['level'] ?? null, $entry['evidence_quality'] ?? null], [
                    $point['competency']['competencies'][$i]['status'], $point['competency']['competencies'][$i]['score'],
                    $point['competency']['competencies'][$i]['level'], $point['competency']['competencies'][$i]['evidence_quality'],
                ]);
            }
        }
        $before = self::by($earlier['competency']['competencies'], 'key', 'FUNCTION_DESIGN');
        $after = self::by($latest['competency']['competencies'], 'key', 'FUNCTION_DESIGN');
        $this->assertNotSame($before['level'], $after['level'], 'a level transition stays visible');
        $levelUp = array_values(array_filter($latest['growth']['events'], fn (array $e): bool => $e['kind'] === 'LEVEL_UP' && $e['metric_key'] === 'FUNCTION_DESIGN'));
        $this->assertSame([$before['level'], $after['level']], [$levelUp[0]['previous_level'], $levelUp[0]['current_level']]);
    }

    public function test_a_resolved_gap_stays_visible_in_history(): void
    {
        $first = $this->assess('manyGaps');
        $this->assess();

        [$latest, $earlier] = $this->read()->json('data');
        $was = self::by($earlier['skill_gaps']['results'], 'competency_key', 'FUNCTION_DESIGN');
        $now = self::by($latest['skill_gaps']['results'], 'competency_key', 'FUNCTION_DESIGN');
        $stored = SkillGapResult::query()->where('skill_gap_snapshot_id', $first->id)->where('competency_key', 'FUNCTION_DESIGN')->sole();

        $this->assertSame(['GAP', $stored->raw_gap, $stored->priority?->value, $stored->current_score, $stored->target_score, true],
            [$was['status'], $was['gap'], $was['priority'], $was['current_score'], $was['target_score'], $was['material_gap']]);
        $this->assertNotNull($was['priority']);
        $this->assertSame(['NO_GAP', null, false], [$now['status'], $now['priority'], $now['material_gap']]);
        $this->assertSame('GAPS_IDENTIFIED', $earlier['skill_gaps']['status']);
        $this->assertSame(['competency_key', 'name', 'status', 'current_score', 'target_score', 'gap', 'material_gap', 'priority', 'evidence_quality', 'current_level'], array_keys($was));
        $closed = array_values(array_filter($latest['growth']['events'], fn (array $e): bool => $e['kind'] === 'GAP_CLOSED' && $e['metric_key'] === 'FUNCTION_DESIGN'));
        $this->assertCount(1, $closed);
    }

    public function test_unmeasured_gaps_carry_no_values(): void
    {
        $this->assess();

        foreach ($this->read()->json('data.0.skill_gaps.results') as $result) {
            if (! in_array($result['status'], ['GAP', 'NO_GAP'], true)) {
                $this->assertSame([null, null], [$result['gap'], $result['priority']], $result['status']);
            } else {
                $this->assertNotNull($result['gap']);
            }
        }
    }

    // Comparison -------------------------------------------------------------

    public function test_adjacent_points_return_the_stored_growth(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();
        $growth = GrowthSnapshot::query()->where('skill_gap_snapshot_id', $second->id)->sole();
        $stored = $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/growth/{$growth->id}")->assertOk()->json('data');
        $count = GrowthSnapshot::query()->count();

        $data = $this->compare($first->dna_snapshot_id, $second->dna_snapshot_id)->assertOk()->json('data');

        $this->assertSame(['type', 'notice', 'status', 'basis', 'growth_snapshot_id', 'rules', 'from', 'to', 'layers', 'differences', 'summary', 'events', 'dna', 'competencies', 'skill_gaps', 'activity'], array_keys($data));
        $this->assertSame(['COMPARED', 'GROWTH_SNAPSHOT', $growth->id], [$data['status'], $data['basis'], $data['growth_snapshot_id']]);
        $this->assertSame(['dna' => 'COMPARED', 'competency' => 'COMPARED', 'skill_gaps' => 'COMPARED'], $data['layers']);
        $this->assertSame([$first->dna_snapshot_id, $second->dna_snapshot_id], [$data['from']['id'], $data['to']['id']]);
        $this->assertSame([$stored['dna'], $stored['competencies'], $stored['skill_gaps'], $stored['events']],
            [$data['dna'], $data['competencies'], $data['skill_gaps'], $data['events']]);
        $this->assertEquals($stored['summary'], $data['summary']);
        $this->assertSame(['observations', 'statuses', 'level_changes'], array_keys($data['summary']));
        $this->assertSame(['DNA', 'COMPETENCY', 'SKILL_GAP'], array_keys($data['summary']['statuses']));
        $overall = self::by($data['dna'], 'metric_key', 'OVERALL');
        $this->assertSame(['0.3050', '0.8050', '0.5000', 'IMPROVED'], [$overall['previous_value'], $overall['current_value'], $overall['delta'], $overall['status']]);
        $this->assertSame($count, GrowthSnapshot::query()->count(), 'comparing stores nothing');
    }

    public function test_the_phase_18_engine_gives_the_same_result_as_stored_growth(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();
        $stored = $this->compare($first->dna_snapshot_id, $second->dna_snapshot_id)->json('data');
        // Without the stored growth, the same rules over the same stored values.
        DB::table('growth_observations')->delete();
        DB::table('growth_snapshots')->where('skill_gap_snapshot_id', $second->id)->delete();

        $computed = $this->compare($first->dna_snapshot_id, $second->dna_snapshot_id)->assertOk()->json('data');

        $this->assertSame(['GROWTH_RULES', null], [$computed['basis'], $computed['growth_snapshot_id']]);
        foreach (['status', 'layers', 'differences', 'summary', 'events', 'dna', 'competencies', 'skill_gaps'] as $field) {
            $this->assertSame($stored[$field], $computed[$field], $field);
        }
    }

    public function test_non_adjacent_points_are_compared_with_the_phase_18_rules_and_nothing_is_stored(): void
    {
        $a = $this->assess('manyGaps');
        $this->assess();
        $c = $this->assess('manyGaps');
        $before = DB::table('growth_snapshots')->orderBy('id')->get()->all();

        $data = $this->compare($a->dna_snapshot_id, $c->dna_snapshot_id)->assertOk()->json('data');

        $this->assertSame(['COMPARED', 'GROWTH_RULES', null], [$data['status'], $data['basis'], $data['growth_snapshot_id']]);
        $overall = self::by($data['dna'], 'metric_key', 'OVERALL');
        $this->assertSame(['0.3050', '0.3050', '0.0000', 'UNCHANGED'], [$overall['previous_value'], $overall['current_value'], $overall['delta'], $overall['status']]);
        $gap = self::by($data['skill_gaps'], 'metric_key', 'FUNCTION_DESIGN');
        $this->assertSame(['GAP', 'GAP'], [$gap['previous_state'], $gap['current_state']]);
        $this->assertEquals($before, DB::table('growth_snapshots')->orderBy('id')->get()->all());
    }

    public function test_the_server_orders_the_two_points_by_time(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();

        $data = $this->compare($second->dna_snapshot_id, $first->dna_snapshot_id)->assertOk()->json('data');

        $this->assertSame([$first->dna_snapshot_id, $second->dna_snapshot_id], [$data['from']['id'], $data['to']['id']]);
        $this->assertSame('0.5000', self::by($data['dna'], 'metric_key', 'OVERALL')['delta']);
    }

    public function test_different_scoring_versions_are_incomparable_without_deltas(): void
    {
        $a = $this->assess();
        $b = $this->assess('manyGaps', before: fn (SkillGapSnapshot $g) => DB::table('dna_snapshots')->where('id', $g->dna_snapshot_id)->update(['scoring_version' => '2.0.0']));
        $c = $this->assess('manyGaps', track: false, before: fn (SkillGapSnapshot $g) => DB::table('dna_snapshots')->where('id', $g->dna_snapshot_id)->update(['scoring_version' => '2.0.0']));

        foreach ([[$a, $b, 'GROWTH_SNAPSHOT'], [$a, $c, 'GROWTH_RULES']] as [$from, $to, $basis]) {
            $data = $this->compare($from->dna_snapshot_id, $to->dna_snapshot_id)->assertOk()->json('data');
            $this->assertSame(['INCOMPARABLE', $basis], [$data['status'], $data['basis']]);
            $this->assertContains('dna_scoring_version', $data['differences']);
            $this->assertSame([[], [], [], []], [$data['dna'], $data['competencies'], $data['skill_gaps'], $data['events']]);
            $this->assertSame(['dna' => 'INCOMPARABLE', 'competency' => 'INCOMPARABLE', 'skill_gaps' => 'INCOMPARABLE'], $data['layers']);
            $this->assertStringNotContainsString('"delta"', $this->compare($from->dna_snapshot_id, $to->dna_snapshot_id)->getContent());
        }
        // Same version again: comparable.
        $this->assertSame('COMPARED', $this->compare($b->dna_snapshot_id, $c->dna_snapshot_id)->json('data.status'));
    }

    public function test_different_competency_or_skill_gap_versions_are_incomparable(): void
    {
        $a = $this->assess();
        $this->assess();
        $competency = $this->assess(track: false, before: fn (SkillGapSnapshot $g) => DB::table('competency_snapshots')->where('id', $g->competency_snapshot_id)->update(['competency_version' => '0.9.0']));
        $gaps = $this->assess(track: false, before: fn (SkillGapSnapshot $g) => DB::table('skill_gap_snapshots')->where('id', $g->id)->update(['skill_gap_version' => '0.9.0']));
        $profile = $this->assess(track: false, before: fn (SkillGapSnapshot $g) => DB::table('skill_gap_snapshots')->where('id', $g->id)->update(['target_profile' => 'OTHER_PROFILE']));

        foreach ([[$competency, 'competency_version'], [$gaps, 'skill_gap_version'], [$profile, 'target_profile']] as [$to, $field]) {
            $data = $this->compare($a->dna_snapshot_id, $to->dna_snapshot_id)->assertOk()->json('data');
            $this->assertSame(['INCOMPARABLE', [$field]], [$data['status'], $data['differences']], $field);
            $this->assertSame([[], [], []], [$data['dna'], $data['competencies'], $data['skill_gaps']]);
        }
    }

    public function test_only_layers_both_points_have_are_compared(): void
    {
        $gaps = $this->assess();
        $dna = $this->dnaOnly();

        $data = $this->compare($gaps->dna_snapshot_id, $dna->id)->assertOk()->json('data');

        $this->assertSame(['COMPARED', 'GROWTH_RULES'], [$data['status'], $data['basis']]);
        $this->assertSame(['dna' => 'COMPARED', 'competency' => 'UNAVAILABLE', 'skill_gaps' => 'UNAVAILABLE'], $data['layers']);
        $this->assertSame(['OVERALL', 'CODE_HYGIENE', 'COMPLEXITY', 'STRUCTURE'], array_column($data['dna'], 'metric_key'));
        $this->assertSame([[], []], [$data['competencies'], $data['skill_gaps']]);
        $this->assertSame(0, $data['summary']['statuses']['COMPETENCY']['INSUFFICIENT_EVIDENCE']);
    }

    public function test_comparison_never_crosses_projects_or_users(): void
    {
        $mine = $this->assess();
        $otherProject = Project::factory()->for($this->owner)->create();
        $sibling = $this->assess(project: $otherProject);
        $stranger = User::factory()->create();
        $theirs = $this->assess(project: Project::factory()->for($stranger)->create());

        $this->compare($mine->dna_snapshot_id, $sibling->dna_snapshot_id)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->compare($mine->dna_snapshot_id, $theirs->dna_snapshot_id)->assertNotFound();
        $this->compare($sibling->dna_snapshot_id, $mine->dna_snapshot_id, project: $otherProject)->assertNotFound();
        $this->compare($mine->dna_snapshot_id, strtolower((string) Str::ulid()))->assertNotFound();
        // Another user cannot compare the owner's points at all.
        $other = $this->assess();
        $this->compare($mine->dna_snapshot_id, $other->dna_snapshot_id, as: $stranger)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->compare($mine->dna_snapshot_id, $other->dna_snapshot_id)->assertOk();
    }

    public function test_comparison_accepts_two_snapshot_ids_and_nothing_else(): void
    {
        $a = $this->assess('manyGaps');
        $b = $this->assess();

        $this->read("/compare?from={$a->dna_snapshot_id}")->assertUnprocessable();
        $this->read('/compare')->assertUnprocessable();
        $this->read("/compare?from=nope&to={$b->dna_snapshot_id}")->assertUnprocessable();
        $this->compare($a->dna_snapshot_id, $a->dna_snapshot_id)->assertUnprocessable();
        $this->compare($a->dna_snapshot_id, strtoupper($a->dna_snapshot_id))->assertUnprocessable();
        foreach (['delta=0.9', 'score=1', 'status=COMPARED', 'scoring_version=1.0.0', 'basis=GROWTH_SNAPSHOT'] as $extra) {
            $this->read("/compare?from={$a->dna_snapshot_id}&to={$b->dna_snapshot_id}&{$extra}")->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->compare(strtoupper($a->dna_snapshot_id), $b->dna_snapshot_id)->assertOk()->assertJsonPath('data.dna.0.delta', '0.5000');
    }

    // Provenance -------------------------------------------------------------

    public function test_uploaded_and_github_sources_show_their_provenance_only(): void
    {
        $upload = $this->assess();
        $github = $this->assess(source: [
            'source_type' => SourceType::Repository,
            'metadata' => [
                'archive' => ['format' => 'zip'],
                'provenance' => [
                    'provider' => 'github', 'repository_id' => 987654321, 'repository' => 'octo-org/example-repo', 'ref' => 'main',
                    'commit_sha' => self::COMMIT, 'import_id' => strtolower((string) Str::ulid()), 'imported_at' => now()->toIso8601ZuluString(),
                ],
            ],
        ]);

        [$pg, $pu] = $this->read()->json('data');
        $source = SourceSnapshot::query()->findOrFail($upload->source_snapshot_id);
        $this->assertSame([
            'snapshot_id' => $source->id, 'version' => $source->version, 'origin' => 'UPLOAD', 'source_hash' => $source->source_hash,
            'file_count' => $source->file_count, 'primary_language' => $source->primary_language, 'created_at' => $source->created_at?->toIso8601ZuluString(), 'github' => null,
        ], $pu['source']);
        $this->assertSame([$github->source_snapshot_id, 'GITHUB'], [$pg['source']['snapshot_id'], $pg['source']['origin']]);
        $this->assertSame(['repository' => 'octo-org/example-repo', 'ref' => 'main', 'commit_sha' => self::COMMIT], $pg['source']['github']);

        $content = $this->read()->getContent().$this->read("/{$github->dna_snapshot_id}")->getContent().$this->compare($upload->dna_snapshot_id, $github->dna_snapshot_id)->getContent();
        foreach (['987654321', 'import_id', 'repository_id', 'installation', 'token', 'storage_key', 'storage_disk', 'snapshots/', 'user_id', $this->owner->id, 'http://', 'https://', 'result_hash', '"evidence"', 'storage'] as $secret) {
            $this->assertStringNotContainsString($secret, $content, $secret);
        }
    }

    public function test_malformed_github_provenance_is_not_passed_through(): void
    {
        $this->assess(source: [
            'source_type' => SourceType::Repository,
            'metadata' => ['provenance' => ['provider' => 'github', 'repository' => 'https://internal.example/x', 'ref' => "main\nX", 'commit_sha' => 'ABC']],
        ]);

        $this->assertSame(['repository' => null, 'ref' => null, 'commit_sha' => null], $this->read()->json('data.0.source.github'));
    }

    // Growth and activity ----------------------------------------------------

    public function test_points_link_their_stored_growth_and_reading_calculates_none(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();
        $untracked = $this->assess(track: false);
        $before = DB::table('growth_snapshots')->orderBy('id')->get()->all();

        [$pu, $p2, $p1] = $this->read()->json('data');
        $growth = GrowthSnapshot::query()->where('skill_gap_snapshot_id', $second->id)->sole();
        $this->assertSame([$growth->id, 'COMPARED', $first->dna_snapshot_id], [$p2['growth']['id'], $p2['growth']['status'], $p2['growth']['previous_dna_snapshot_id']]);
        $this->assertEquals($growth->summary, $p2['growth']['summary']);
        $this->assertSame('NOT_ESTABLISHED', $p1['growth']['status']);
        $this->assertNull($pu['growth'], 'not calculated is not invented');
        $this->assertSame($untracked->dna_snapshot_id, $pu['id']);
        $this->read("/{$untracked->dna_snapshot_id}")->assertOk();
        $this->assertEquals($before, DB::table('growth_snapshots')->orderBy('id')->get()->all());
    }

    public function test_growth_of_other_rules_is_neither_linked_nor_reused(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();
        $this->app->instance(GrowthRules::class, new GrowthRules('9.9.9', GrowthRules::v1_0_0()->meaningfulDelta, GrowthRules::v1_0_0()->minimumEvidenceQuality, GrowthRules::v1_0_0()->measuredStates));

        $this->assertSame([null, null], array_column($this->read()->json('data'), 'growth'));
        $data = $this->compare($first->dna_snapshot_id, $second->dna_snapshot_id)->assertOk()->json('data');
        $this->assertSame(['GROWTH_RULES', null, '9.9.9'], [$data['basis'], $data['growth_snapshot_id'], $data['rules']['version']]);
    }

    public function test_detail_has_neighbours_and_activity_as_context_only(): void
    {
        $gaps = $this->assess('manyGaps');
        $roadmap = $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/roadmaps")->assertCreated()->json('data');
        $this->travel(1)->minutes();
        foreach (array_slice($roadmap['tracks'][0]['steps'], 0, 2) as $step) {
            $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/roadmaps/{$roadmap['id']}/steps/{$step['key']}/complete")->assertOk();
        }
        $challenge = $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/challenges", ['skill_gap_snapshot_id' => $gaps->id])->assertCreated()->json('data.id');
        $submission = $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/challenges/{$challenge}/submissions", ['language' => 'python', 'source' => "def f():\n    return 1\n"])->json('data.id');
        app()->call([new EvaluateChallengeSubmission($submission), 'handle']);
        $values = $this->read()->json('data.0');
        $second = $this->assess();
        $third = $this->assess();

        $detail = $this->read("/{$second->dna_snapshot_id}")->assertOk()->json('data');
        $this->assertSame($gaps->dna_snapshot_id, $detail['previous']['dna_snapshot_id']);
        $this->assertSame($third->dna_snapshot_id, $detail['next']['dna_snapshot_id']);
        $this->assertSame(['roadmap_steps_completed' => 2, 'challenges_passed' => 1], $detail['activity']);
        $this->assertSame(['roadmap_steps_completed' => 0, 'challenges_passed' => 0], $this->read("/{$third->dna_snapshot_id}")->json('data.activity'));
        $this->assertNull($this->read("/{$gaps->dna_snapshot_id}")->json('data.activity'));
        $this->assertSame(['roadmap_steps_completed' => 2, 'challenges_passed' => 1], $this->compare($gaps->dna_snapshot_id, $third->dna_snapshot_id)->json('data.activity'));
        // Activity never changes a historical value.
        $this->assertSame($values, $this->read()->json('data.2'));
    }

    // Immutability and security ---------------------------------------------

    public function test_history_is_read_only_and_snapshots_cannot_change(): void
    {
        $gaps = $this->assess();
        $base = "/api/v1/projects/{$this->project->id}/history";
        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->asUser($this->owner)->{$method}($base, ['overall_score' => '1.0000'])->assertStatus(405);
            $this->asUser($this->owner)->{$method}("{$base}/{$gaps->dna_snapshot_id}", ['overall_score' => '1.0000'])->assertStatus(405);
        }

        $dna = DnaSnapshot::query()->findOrFail($gaps->dna_snapshot_id);
        $dna->overall_score = '1.0000';
        try {
            $dna->save();
            $this->fail('A DNA snapshot was updated.');
        } catch (DomainRuleViolation) {
        }
        foreach ([CompetencySnapshot::query()->findOrFail($gaps->competency_snapshot_id), $gaps, SkillGapResult::query()->where('skill_gap_snapshot_id', $gaps->id)->firstOrFail()] as $model) {
            try {
                $model->delete();
                $this->fail($model::class.' was deleted.');
            } catch (DomainRuleViolation) {
            }
        }
        $this->assertSame('0.8050', $this->read()->json('data.0.dna.overall_score'));
    }

    public function test_history_is_owner_only_and_archived_projects_stay_readable(): void
    {
        $gaps = $this->assess();
        $stranger = User::factory()->create();

        $this->read(as: $stranger)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->read("/{$gaps->dna_snapshot_id}", $stranger)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

        // A snapshot of another project, through this project, is not found.
        $foreign = $this->assess(project: Project::factory()->for($this->owner)->create());
        $this->read("/{$foreign->dna_snapshot_id}")->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->read('/'.strtolower((string) Str::ulid()))->assertNotFound();

        $this->project->archive();
        $this->read()->assertOk()->assertJsonPath('meta.total', 1);
        $this->read("/{$gaps->dna_snapshot_id}")->assertOk();
    }

    public function test_history_requires_authentication(): void
    {
        $id = strtolower((string) Str::ulid());
        foreach (['', "/{$id}", "/compare?from={$id}&to={$id}"] as $suffix) {
            $this->getJson("/api/v1/projects/{$this->project->id}/history{$suffix}")->assertUnauthorized();
        }
    }

    // Performance ------------------------------------------------------------

    public function test_reads_use_a_fixed_number_of_queries(): void
    {
        $counts = [];
        foreach ([2, 6] as $n) {
            while (count($this->project->dnaSnapshots()->get()) < $n) {
                $this->assess(count($this->project->dnaSnapshots()->get()) % 2 === 0 ? 'manyGaps' : 'oneGap');
            }
            $ids = $this->project->dnaSnapshots()->pluck('id')->all();
            $this->asUser($this->owner);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/projects/{$this->project->id}/history")->assertOk();
            $index = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->getJson("/api/v1/projects/{$this->project->id}/history/compare?from={$ids[0]}&to={$ids[1]}")->assertOk();
            $compare = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->getJson("/api/v1/projects/{$this->project->id}/history/{$ids[0]}")->assertOk();
            $show = count(DB::getQueryLog());
            DB::disableQueryLog();
            $counts[$n] = [$index, $compare, $show];
        }

        $this->assertSame($counts[2], $counts[6], 'independent of the history length');
        $this->assertLessThanOrEqual(15, $counts[6][0]);
    }
}
