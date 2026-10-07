<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\ChallengeFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\TestCase;

/**
 * The read-only growth API (Phase 18): owner-only, stored values only,
 * learning activity as context only, and no way for a client to create or
 * change growth. No AI (AI_ENABLED=false throughout).
 */
final class GrowthApiTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = 'Growth compares deterministic code assessments only. Completed learning steps and challenges are not growth evidence; only a new code analysis can show change.';

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => false, 'codedna.challenges.enabled' => true]);
        $this->app->bind(AiProvider::class, fn () => throw new RuntimeException('Growth must never use the AI provider.'));
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator('pass'));
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
    }

    private function path(string $suffix = '', ?Project $project = null): string
    {
        return '/api/v1/projects/'.($project ?? $this->project)->id."/growth{$suffix}";
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function read(string $suffix = '', ?User $as = null, ?Project $project = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->getJson($this->path($suffix, $project));
    }

    /**
     * A new assessment one hour later, tracked as the analysis job does.
     */
    private function assess(string $kind = 'oneGap', ?Project $project = null, bool $track = true): SkillGapSnapshot
    {
        $this->travel(1)->hours();
        $gaps = $kind === 'manyGaps' ? ChallengeFixtures::manyGaps($project ?? $this->project) : ChallengeFixtures::oneGap($project ?? $this->project);
        if ($track) {
            app(CalculateGrowthSnapshot::class)->handle($gaps->id);
        }

        return $gaps;
    }

    public function test_a_project_without_an_assessment_has_no_growth(): void
    {
        $this->read()->assertOk()->assertExactJson(['data' => ['state' => 'NO_ASSESSMENT', 'notice' => self::NOTICE, 'latest' => null, 'series' => []]]);
        $this->read('/timeline')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_one_assessment_has_no_baseline(): void
    {
        $gaps = $this->assess();

        $data = $this->read()->assertOk()->json('data');

        $this->assertSame('NOT_ESTABLISHED', $data['state']);
        $this->assertSame('NOT_ESTABLISHED', $data['latest']['status']);
        $this->assertSame($gaps->id, $data['latest']['current']['skill_gap_snapshot_id']);
        $this->assertNull($data['latest']['previous']);
        $this->assertNull($data['latest']['previous_assessed_at']);
        $this->assertSame([[], [], []], [$data['latest']['dna'], $data['latest']['competencies'], $data['latest']['skill_gaps']]);
        $this->assertSame([], $data['latest']['events']);
        $this->assertNull($data['latest']['activity'], 'no window without a baseline');
        $this->assertSame([], $data['series']);
    }

    public function test_an_untracked_newest_assessment_is_reported(): void
    {
        $this->assess();
        $this->assess(track: false);

        $this->read()->assertOk()->assertJsonPath('data.state', 'NOT_CALCULATED')->assertJsonPath('data.latest.status', 'NOT_ESTABLISHED');
    }

    public function test_two_assessments_are_compared_with_provenance_and_events(): void
    {
        $first = $this->assess('manyGaps');
        $second = $this->assess();

        $data = $this->read()->assertOk()->json('data');
        $latest = $data['latest'];

        $this->assertSame('COMPARED', $data['state']);
        $this->assertSame(self::NOTICE, $latest['notice']);
        $this->assertSame(['skill_gap_snapshot_id' => $first->id, 'competency_snapshot_id' => $first->competency_snapshot_id, 'dna_snapshot_id' => $first->dna_snapshot_id, 'analysis_run_id' => $first->analysis_run_id, 'source_snapshot_id' => $first->source_snapshot_id], $latest['previous']);
        $this->assertSame($second->id, $latest['current']['skill_gap_snapshot_id']);
        $this->assertSame(['version' => '1.0.0', 'fingerprint' => 'b983b8b800846927fbcfed9cd806026c7994ca2a8c86ecd58d22dca021fdf321', 'current' => true], $latest['rules']);
        $this->assertSame($latest['versions'], $latest['previous_versions']);
        $this->assertSame([], $latest['differences']);
        $this->assertSame(['id', 'type', 'project_id', 'status', 'assessed_at', 'previous_assessed_at', 'current', 'previous', 'summary', 'events',
            'rules_version', 'created_at', 'notice', 'versions', 'previous_versions', 'differences', 'rules', 'dna', 'competencies', 'skill_gaps', 'activity'], array_keys($latest));
        $this->assertSame(['state', 'notice', 'latest', 'series'], array_keys($data));

        $overall = collect($latest['dna'])->firstWhere('metric_key', 'OVERALL');
        $this->assertSame(['0.3050', '0.8050', '0.5000', 'IMPROVED', 'HIGHER'], [$overall['previous_value'], $overall['current_value'], $overall['delta'], $overall['status'], $overall['better']]);
        $gap = collect($latest['skill_gaps'])->firstWhere('metric_key', 'FUNCTION_DESIGN');
        $this->assertSame(['GAP', 'NO_GAP', 'IMPROVED', 'LOWER'], [$gap['previous_state'], $gap['current_state'], $gap['status'], $gap['better']]);
        $competency = collect($latest['competencies'])->firstWhere('metric_key', 'FUNCTION_DESIGN');
        $this->assertSame('UP', $competency['level_change']);

        $types = array_count_values(array_column($latest['events'], 'kind'));
        $this->assertSame(3, $types['GAP_CLOSED']);
        $this->assertSame(3, $types['LEVEL_UP']);
        $this->assertArrayNotHasKey('REGRESSED', $types);
        $levelUp = collect($latest['events'])->firstWhere('kind', 'LEVEL_UP');
        $this->assertSame(['kind', 'previous_level', 'current_level', 'metric_type', 'metric_key', 'previous_value', 'current_value', 'delta'], array_keys($levelUp));

        // Two assessments: a before/after pair per metric, never a longer line.
        $series = collect($data['series'])->first(fn (array $s): bool => $s['metric_type'] === 'DNA' && $s['metric_key'] === 'OVERALL');
        $this->assertSame(['0.3050', '0.8050'], array_column($series['points'], 'value'));
    }

    public function test_the_series_never_crosses_a_missing_baseline_or_a_version_change(): void
    {
        $this->assess('manyGaps');
        $this->assess();
        $third = $this->assess('manyGaps', track: false);
        DB::table('competency_snapshots')->where('id', $third->competency_snapshot_id)->update(['specification_fingerprint' => str_repeat('a', 64)]);
        app(CalculateGrowthSnapshot::class)->handle($third->id);

        $data = $this->read()->assertOk()->json('data');
        $this->assertSame('INCOMPARABLE', $data['state']);
        $this->assertSame(['competency_specification_fingerprint'], $data['latest']['differences']);
        $this->assertSame([[], [], []], [$data['latest']['dna'], $data['latest']['competencies'], $data['latest']['skill_gaps']], 'no fake delta');
        $this->assertSame([], $data['series']);

        $fourth = $this->assess();
        DB::table('competency_snapshots')->where('id', $fourth->competency_snapshot_id)->update(['specification_fingerprint' => str_repeat('a', 64)]);
        DB::table('growth_observations')->delete();
        DB::table('growth_snapshots')->delete();
        $this->artisan('growth:calculate', ['--missing' => true])->assertSuccessful();
        $points = collect($this->read()->json('data.series'))->firstWhere('metric_key', 'OVERALL')['points'];
        $this->assertSame(['0.3050', '0.8050'], array_column($points, 'value'), 'only the newest unbroken comparison');
    }

    public function test_three_compared_assessments_form_a_trend(): void
    {
        $this->assess('manyGaps');
        $this->assess();
        $this->assess('manyGaps');

        $points = collect($this->read()->json('data.series'))->firstWhere('metric_key', 'OVERALL')['points'];

        $this->assertSame(['0.3050', '0.8050', '0.3050'], array_column($points, 'value'));
        $this->assertSame(array_values(collect(array_column($points, 'assessed_at'))->sort()->all()), array_column($points, 'assessed_at'));
    }

    public function test_the_timeline_is_newest_first_and_paginated(): void
    {
        $gaps = [$this->assess('manyGaps'), $this->assess(), $this->assess('manyGaps')];

        $timeline = $this->read('/timeline?per_page=2')->assertOk();

        $timeline->assertJsonPath('meta.total', 3);
        $this->assertSame([$gaps[2]->id, $gaps[1]->id], array_column(array_column($timeline->json('data'), 'current'), 'skill_gap_snapshot_id'));
        $this->assertSame(['COMPARED', 'COMPARED'], array_column($timeline->json('data'), 'status'));
        $this->assertSame([$gaps[0]->id], array_column(array_column($this->read('/timeline?per_page=2&page=2')->json('data'), 'current'), 'skill_gap_snapshot_id'));
        $this->read('/timeline?per_page=500')->assertUnprocessable();
    }

    public function test_one_growth_snapshot_is_shown_by_id(): void
    {
        $this->assess('manyGaps');
        $this->assess();
        $id = GrowthSnapshot::query()->where('status', 'COMPARED')->value('id');

        $this->read("/{$id}")->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'COMPARED')->assertJsonPath('data.notice', self::NOTICE);
    }

    /**
     * Learning activity is context between the two assessments. It is never
     * growth: completing steps or passing challenges creates and changes nothing.
     */
    public function test_learning_activity_is_context_only_and_never_creates_growth(): void
    {
        $gaps = $this->assess('manyGaps');
        $before = DB::table('growth_snapshots')->get()->all();

        $roadmap = $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/roadmaps")->assertCreated()->json('data');
        // At the very time of the first assessment: before it, as far as the window (previous, current] goes.
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/roadmaps/{$roadmap['id']}/steps/{$roadmap['tracks'][0]['steps'][0]['key']}/complete")->assertOk();
        $this->travel(1)->minutes();
        foreach (array_slice($roadmap['tracks'][0]['steps'], 1, 2) as $step) {
            $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/roadmaps/{$roadmap['id']}/steps/{$step['key']}/complete")->assertOk();
        }
        $challenge = $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/challenges", ['skill_gap_snapshot_id' => $gaps->id])->assertCreated()->json('data.id');
        $submission = $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/challenges/{$challenge}/submissions", ['language' => 'python', 'source' => "def f():\n    return 1\n"])->json('data.id');
        app()->call([new EvaluateChallengeSubmission($submission), 'handle']);
        $this->assertSame('PASSED', DB::table('challenge_submissions')->where('id', $submission)->value('status'));

        $this->assertEquals($before, DB::table('growth_snapshots')->get()->all(), 'learning creates no growth');
        $this->assertSame(0, DB::table('growth_observations')->count());
        $this->read()->assertJsonPath('data.state', 'NOT_ESTABLISHED')->assertJsonPath('data.latest.activity', null);

        // Only a new code assessment shows change; the activity is listed next to it.
        $this->assess();
        $latest = $this->read()->assertOk()->json('data.latest');
        $this->assertSame('COMPARED', $latest['status']);
        $this->assertSame(['roadmap_steps_completed' => 2, 'challenges_passed' => 1], $latest['activity']);

        // Activity after the newest assessment belongs to the next comparison.
        $this->travel(1)->minutes();
        $step = $roadmap['tracks'][0]['steps'][3]['key'];
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/roadmaps/{$roadmap['id']}/steps/{$step}/complete")->assertOk();
        $this->read()->assertJsonPath('data.latest.activity', ['roadmap_steps_completed' => 2, 'challenges_passed' => 1]);
    }

    /**
     * Growth calculated with other rules stays readable and says so.
     */
    public function test_growth_from_other_rules_is_marked_as_not_current(): void
    {
        $this->assess('manyGaps');
        $this->assess();
        $row = (array) DB::table('growth_snapshots')->where('status', 'COMPARED')->first();
        $old = strtolower((string) Str::ulid());
        DB::table('growth_snapshots')->insert(['id' => $old, 'rules_version' => '0.9.0', 'rules_fingerprint' => str_repeat('d', 64)] + $row);

        $this->read("/{$old}")->assertOk()->assertJsonPath('data.rules', ['version' => '0.9.0', 'fingerprint' => str_repeat('d', 64), 'current' => false]);
        $this->read()->assertJsonPath('data.latest.rules.current', true)->assertJsonPath('data.latest.rules_version', '1.0.0');
    }

    public function test_archived_projects_keep_their_growth(): void
    {
        $this->assess('manyGaps');
        $this->assess();
        $this->project->archive();

        $this->read()->assertOk()->assertJsonPath('data.state', 'COMPARED');
        $this->read('/timeline')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_growth_is_owner_only_and_never_crosses_projects(): void
    {
        $this->assess('manyGaps');
        $this->assess();
        $id = GrowthSnapshot::query()->where('status', 'COMPARED')->value('id');
        $stranger = User::factory()->create();
        $theirs = Project::factory()->for($stranger)->create();
        $mine = Project::factory()->for($this->owner)->create();

        foreach (['', '/timeline', "/{$id}"] as $suffix) {
            $this->read($suffix, $stranger)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        }
        $this->read("/{$id}", $stranger, $theirs)->assertNotFound();
        $this->read("/{$id}", null, $mine)->assertNotFound();
        $this->read('/01k6p0a1b2c3d4e5f6g7h8j9zz')->assertNotFound();
        $this->read('', null, $mine)->assertJsonPath('data.state', 'NO_ASSESSMENT');
    }

    /**
     * There is no write route; clients choose no snapshot, value or version.
     */
    public function test_growth_cannot_be_written_or_steered_by_the_client(): void
    {
        $this->assess('manyGaps');
        $this->assess();
        $id = GrowthSnapshot::query()->where('status', 'COMPARED')->value('id');
        $before = [DB::table('growth_snapshots')->orderBy('id')->get()->all(), DB::table('growth_observations')->orderBy('id')->get()->all()];
        $body = ['status' => 'IMPROVED', 'delta' => '1.0000', 'rules_version' => '0.0.1', 'skill_gap_snapshot_id' => 'x', 'current_value' => '1.0000'];

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            foreach (['', '/timeline', "/{$id}"] as $suffix) {
                $this->asUser($this->owner)->{$method}($this->path($suffix), $body)->assertStatus(405);
            }
        }
        $this->read('?rules_version=0.0.1&status=IMPROVED&baseline=oldest')->assertOk()->assertJsonPath('data.latest.rules.version', '1.0.0')->assertJsonPath('data.latest.id', $id);
        $this->assertEquals($before, [DB::table('growth_snapshots')->orderBy('id')->get()->all(), DB::table('growth_observations')->orderBy('id')->get()->all()]);
    }

    public function test_growth_requires_authentication(): void
    {
        foreach (['', '/timeline', '/01k6p0a1b2c3d4e5f6g7h8j9zz'] as $suffix) {
            $this->getJson($this->path($suffix))->assertUnauthorized();
        }
    }

    public function test_responses_leak_nothing_internal(): void
    {
        $this->assess('manyGaps');
        $this->assess();
        $id = GrowthSnapshot::query()->where('status', 'COMPARED')->value('id');

        foreach (['', '/timeline', "/{$id}"] as $suffix) {
            $body = (string) $this->read($suffix)->getContent();
            $this->assertStringNotContainsString($this->owner->id, $body);
            $this->assertStringNotContainsString('user_id', $body);
            $this->assertDoesNotMatchRegularExpression('#storage_key|https?://|minio|analyzer|source\.zip|"ir"|"metrics"|"findings"#i', $body);
        }
    }

    public function test_reads_use_a_bounded_number_of_queries(): void
    {
        $count = function (string $suffix): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->read($suffix)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assess('manyGaps');
        $this->assess();
        $few = [$count(''), $count('/timeline')];
        foreach (range(1, 4) as $i) {
            $this->assess($i % 2 === 0 ? 'oneGap' : 'manyGaps');
        }

        $this->assertSame($few, [$count(''), $count('/timeline')]);
        $this->assertLessThanOrEqual(10, $few[0]);
        $this->assertLessThanOrEqual(8, $few[1]);
    }
}
