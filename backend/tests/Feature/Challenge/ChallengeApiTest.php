<?php

declare(strict_types=1);

namespace Tests\Feature\Challenge;

use App\Enums\Challenge\ChallengeStatus;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ChallengeFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\TestCase;

/**
 * /api/v1/projects/{project}/challenges and .../submissions (Phase 16).
 * Requests only record and queue; nothing is executed here, and no AI is
 * involved (AI_ENABLED=false throughout).
 */
final class ChallengeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private FakeChallengeEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => false, 'codedna.challenges.enabled' => true]);
        // Any use of the AI provider fails the test.
        $this->app->bind(AiProvider::class, fn () => throw new RuntimeException('Challenges must never use the AI provider.'));
        $this->evaluator = new FakeChallengeEvaluator('pass');
        $this->app->instance(ChallengeEvaluator::class, $this->evaluator);
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function assign(array $body = [], ?User $as = null, ?Project $project = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->postJson('/api/v1/projects/'.($project ?? $this->project)->id.'/challenges', $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function submit(string $challengeId, array $body = [], array $headers = [], ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->withHeaders($headers)->postJson(
            "/api/v1/projects/{$this->project->id}/challenges/{$challengeId}/submissions",
            $body + ['language' => 'python', 'source' => "def parse_config(text):\n    return {}\n"],
        );
    }

    private function path(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/challenges{$suffix}";
    }

    private function evaluate(string $submissionId): void
    {
        app()->call([new EvaluateChallengeSubmission($submissionId), 'handle']);
    }

    public function test_a_challenge_is_assigned_from_the_top_skill_gap_with_provenance(): void
    {
        $gaps = ChallengeFixtures::oneGap($this->project);

        $response = $this->assign()->assertCreated();

        $response->assertJsonPath('data.status', 'ASSIGNED')
            ->assertJsonPath('data.competency_key', 'CODE_HYGIENE')
            ->assertJsonPath('data.definition', ['key' => 'CODE_HYGIENE_001', 'version' => '1.0.0', 'title' => 'Repair the configuration parser'])
            ->assertJsonPath('data.difficulty', 'BEGINNER')
            ->assertJsonPath('data.language', 'python')
            ->assertJsonPath('data.attempts_used', 0)
            ->assertJsonPath('data.max_attempts', 5)
            ->assertJsonPath('data.notice', 'Completing this challenge does not immediately change your CodeDNA score or skill gap. Reassessment occurs from new code analysis.')
            ->assertJsonPath('data.evaluation_available', true)
            ->assertJsonPath('data.selection.rule', 'TOP_PRIORITY_GAP')
            ->assertJsonPath('data.selection.gap.priority', 'HIGH')
            ->assertJsonPath('data.gap.raw_gap', '0.3000')
            ->assertJsonPath('data.lineage.skill_gap_snapshot_id', $gaps->id)
            ->assertJsonPath('data.lineage.dna_snapshot_id', $gaps->dna_snapshot_id)
            ->assertJsonPath('data.versions', ['definition' => '1.0.0', 'catalog' => '1.0.0', 'selection' => 'challenge-selection/1.0.0', 'evaluation' => 'challenge-evaluation/1.0.0'])
            ->assertJsonPath('data.challenge.entrypoint', 'parse_config')
            ->assertJsonPath('data.challenge.hidden_case_count', 3)
            ->assertJsonPath('data.recent_attempts', []);
        $this->assertCount(2, $response->json('data.challenge.examples'));
    }

    public function test_hidden_tests_never_appear_in_any_response(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        $submission = $this->submit($id)->json('data.id');
        $this->evaluate($submission);

        $bodies = [
            $this->asUser($this->owner)->getJson($this->path())->getContent(),
            $this->asUser($this->owner)->getJson($this->path("/{$id}"))->getContent(),
            $this->asUser($this->owner)->getJson($this->path("/{$id}/submissions"))->getContent(),
            $this->asUser($this->owner)->getJson($this->path("/{$id}/submissions/{$submission}"))->getContent(),
        ];
        $hidden = ['a=1\\nA = 2\\nbroken line\\n b = x = y ', '#only=comment', '"b":"x = y"', 'test_suite_fingerprint', 'spool', '/var/', 'document'];
        foreach ($bodies as $body) {
            foreach ($hidden as $needle) {
                $this->assertStringNotContainsString($needle, (string) $body);
            }
        }
    }

    public function test_assignment_is_idempotent_per_snapshot(): void
    {
        ChallengeFixtures::manyGaps($this->project);
        $first = $this->assign()->assertCreated()->json('data.id');

        $this->assign()->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first);
        $this->assertSame(1, ChallengeInstance::query()->count());

        // Another competency is another challenge, and asking again returns it.
        $second = $this->assign(['competency_key' => 'CODE_HYGIENE'])->assertCreated()->json('data.id');
        $this->assign(['competency_key' => 'CODE_HYGIENE'])->assertOk()->assertJsonPath('data.id', $second);
        $this->assertNotSame($first, $second);
        $this->assertSame(2, ChallengeInstance::query()->count());
    }

    public function test_a_closed_challenge_is_followed_by_the_next_definition(): void
    {
        ChallengeFixtures::manyGaps($this->project);
        $first = $this->assign(['competency_key' => 'COMPLEXITY_MANAGEMENT'])->json('data');
        $this->evaluate($this->submit($first['id'])->json('data.id'));
        $this->assertSame('PASSED', ChallengeInstance::query()->findOrFail($first['id'])->status->value);

        $next = $this->assign(['competency_key' => 'COMPLEXITY_MANAGEMENT'])->assertCreated()->json('data');
        $this->assertSame(['COMPLEXITY_MANAGEMENT_001', 'COMPLEXITY_MANAGEMENT_002'], [$first['definition']['key'], $next['definition']['key']]);
        $this->assertSame(['COMPLEXITY_MANAGEMENT_001'], $next['selection']['excluded_definitions']);

        $this->evaluate($this->submit($next['id'])->json('data.id'));
        $this->assign(['competency_key' => 'COMPLEXITY_MANAGEMENT'])->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGE_NONE_AVAILABLE');
    }

    public function test_a_snapshot_can_be_selected_but_only_from_this_project(): void
    {
        $older = ChallengeFixtures::oneGap($this->project);
        Carbon::setTestNow(Carbon::now()->addMinute());
        ChallengeFixtures::manyGaps($this->project);
        Carbon::setTestNow();
        $foreign = ChallengeFixtures::oneGap(Project::factory()->create());

        $this->assign(['skill_gap_snapshot_id' => strtoupper($older->id)])->assertCreated()->assertJsonPath('data.skill_gap_snapshot_id', $older->id);
        $this->assign(['skill_gap_snapshot_id' => $foreign->id])->assertStatus(422)
            ->assertJsonPath('error.details.fields.skill_gap_snapshot_id', ['The selected skill gap snapshot is invalid.']);
    }

    public function test_assignment_needs_a_material_gap(): void
    {
        $this->assign()->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGE_NO_ELIGIBLE_GAP');

        ChallengeFixtures::oneGap($this->project);
        $this->assign(['competency_key' => 'FUNCTION_DESIGN'])->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGE_NO_ELIGIBLE_GAP');
        $this->assertSame(0, ChallengeInstance::query()->count());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function clientControlledAssignmentFields(): iterable
    {
        yield 'definition' => [['challenge_definition' => ['key' => 'X', 'cases' => []]]];
        yield 'definition key' => [['definition_key' => 'CODE_HYGIENE_001']];
        yield 'command' => [['command' => 'rm -rf /']];
        yield 'image' => [['image' => 'evil/image']];
        yield 'difficulty' => [['difficulty' => 'ADVANCED']];
        yield 'tests' => [['tests' => [['args' => [], 'expected' => 1]]]];
        yield 'unknown competency' => [['competency_key' => 'SECURITY']];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('clientControlledAssignmentFields')]
    public function test_clients_cannot_supply_definitions_tests_or_execution_settings(array $body): void
    {
        ChallengeFixtures::oneGap($this->project);

        $response = $this->assign($body)->assertStatus(422);

        $this->assertSame(0, ChallengeInstance::query()->count());
        $this->assertStringNotContainsString('rm -rf', (string) $response->getContent());
    }

    public function test_a_submission_is_recorded_and_queued_without_running_anything(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        $source = "  def parse_config(text):\n    return {}\n\n";

        $response = $this->submit($id, ['source' => $source])->assertStatus(202);

        $response->assertJsonPath('data.status', 'QUEUED')->assertJsonPath('data.attempt_number', 1)->assertJsonPath('data.source', $source)
            ->assertJsonPath('data.source_sha256', hash('sha256', $source))->assertJsonPath('data.source_bytes', strlen($source));
        $this->assertSame(0, $this->evaluator->calls, 'nothing is evaluated during the request');
        Queue::assertPushedOn('challenge', EvaluateChallengeSubmission::class, fn (EvaluateChallengeSubmission $job) => $job->submissionId === $response->json('data.id'));
        $this->assertSame('EVALUATING', ChallengeInstance::query()->findOrFail($id)->status->value);
        $this->asUser($this->owner)->getJson($this->path("/{$id}"))->assertJsonPath('data.recent_attempts.0.status', 'QUEUED');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSubmissions(): iterable
    {
        yield 'unsupported language' => [['language' => 'bash'], 'language'];
        yield 'no source' => [['source' => ''], 'source'];
        yield 'blank source' => [['source' => "   \n\t\n"], 'source'];
        yield 'too large' => [['source' => str_repeat('#', 16385)], 'source'];
        yield 'too many lines' => [['source' => str_repeat("x = 1\n", 400)], 'source'];
        yield 'NUL byte' => [['source' => "def f():\0\n    pass\n"], 'source'];
        yield 'invalid UTF-8' => [['source' => "def f():\n    return '\xC3\x28'\n"], 'source'];
        yield 'not a string' => [['source' => ['a.py' => 'x']], 'source'];
        yield 'command field' => [['command' => 'python -c "import os"'], 'request'];
        yield 'tests field' => [['tests' => []], 'request'];
        yield 'file name' => [['filename' => '../../etc/passwd'], 'request'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidSubmissions')]
    public function test_invalid_submissions_are_rejected_before_anything_is_stored(array $body, string $field): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');

        $response = $this->submit($id, $body)->assertStatus(422);

        $this->assertArrayHasKey($field, $response->json('error.details.fields'));
        $this->assertSame(0, ChallengeSubmission::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_one_attempt_is_evaluated_at_a_time(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        $this->submit($id)->assertStatus(202);

        $this->submit($id)->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGE_EVALUATION_PENDING');
        $this->assertSame(1, ChallengeSubmission::query()->count());
    }

    public function test_the_attempt_limit_closes_the_challenge(): void
    {
        config(['codedna.challenges.max_attempts' => 2]);
        $this->evaluator = new FakeChallengeEvaluator('wrong');
        $this->app->instance(ChallengeEvaluator::class, $this->evaluator);
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');

        $this->evaluate($this->submit($id)->json('data.id'));
        $this->assertSame(['ASSIGNED', 1], [ChallengeInstance::query()->findOrFail($id)->status->value, ChallengeInstance::query()->findOrFail($id)->attempts_used]);
        $this->evaluate($this->submit($id)->json('data.id'));

        $this->asUser($this->owner)->getJson($this->path("/{$id}"))->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.attempts_used', 2);
        $this->submit($id)->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGE_CLOSED');
    }

    public function test_a_passed_challenge_accepts_no_further_attempts(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        $this->evaluate($this->submit($id)->json('data.id'));

        $this->submit($id)->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGE_CLOSED');
    }

    public function test_an_idempotency_key_replays_the_same_submission(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        $key = ['Idempotency-Key' => 'attempt-0001'];
        $first = $this->submit($id, [], $key)->assertStatus(202)->json('data.id');

        $this->submit($id, [], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first);
        $this->submit($id, ['source' => "def parse_config(text):\n    return {'a': 1}\n"], $key)->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->submit($id, [], ['Idempotency-Key' => 'bad key!'])->assertStatus(400);
        // A replay is answered from the stored attempt, even while the evaluator is down.
        $this->evaluator->isAvailable = false;
        $this->submit($id, [], $key)->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame(1, ChallengeSubmission::query()->count());
        Queue::assertPushed(EvaluateChallengeSubmission::class, 1);
    }

    public function test_submissions_are_refused_while_evaluation_is_unavailable(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        $this->evaluator->isAvailable = false;

        $this->asUser($this->owner)->getJson($this->path("/{$id}"))->assertJsonPath('data.evaluation_available', false);
        $this->submit($id)->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGE_EVALUATION_UNAVAILABLE');
        $this->assertSame(0, ChallengeSubmission::query()->count());
        $this->assertSame('ASSIGNED', ChallengeInstance::query()->findOrFail($id)->status->value);
    }

    public function test_challenges_can_be_disabled(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        config(['codedna.challenges.enabled' => false]);

        $this->assign(['competency_key' => 'CODE_HYGIENE'])->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGES_DISABLED');
        $this->submit($id)->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGES_DISABLED');
        $this->asUser($this->owner)->getJson($this->path("/{$id}"))->assertOk()->assertJsonPath('data.evaluation_available', false);
    }

    public function test_archived_projects_keep_their_history_but_accept_nothing_new(): void
    {
        ChallengeFixtures::manyGaps($this->project);
        $id = $this->assign()->json('data.id');
        $submission = $this->submit($id)->json('data.id');
        $this->evaluate($submission);
        $other = $this->assign(['competency_key' => 'CODE_HYGIENE'])->json('data.id');
        $this->project->archive();

        $this->assign(['competency_key' => 'FUNCTION_DESIGN'])->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        $this->submit($other)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        $this->asUser($this->owner)->getJson($this->path())->assertOk()->assertJsonPath('meta.total', 2);
        $this->asUser($this->owner)->getJson($this->path("/{$id}/submissions/{$submission}"))->assertOk()->assertJsonPath('data.status', 'PASSED');
    }

    public function test_only_the_owner_can_see_or_use_challenges_and_submissions(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        $submission = $this->submit($id)->json('data.id');
        $stranger = User::factory()->create();
        $otherProject = Project::factory()->for($this->owner)->create();

        $this->assign([], $stranger)->assertNotFound();
        foreach (['', "/{$id}", "/{$id}/submissions", "/{$id}/submissions/{$submission}"] as $suffix) {
            $this->asUser($stranger)->getJson($this->path($suffix))->assertNotFound();
        }
        $this->submit($id, [], [], $stranger)->assertNotFound();
        // Scoped bindings: a challenge is only reachable through its own project.
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$otherProject->id}/challenges/{$id}")->assertNotFound();
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$otherProject->id}/challenges/{$id}/submissions/{$submission}")->assertNotFound();
        $this->assertSame(1, ChallengeSubmission::query()->count());
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson($this->path())->assertUnauthorized();
        $this->postJson($this->path())->assertUnauthorized();
    }

    public function test_challenges_and_submissions_cannot_be_changed_or_deleted(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');
        $submission = $this->submit($id)->json('data.id');

        foreach (["/{$id}", "/{$id}/submissions/{$submission}"] as $suffix) {
            $this->asUser($this->owner)->patchJson($this->path($suffix), ['status' => 'PASSED'])->assertStatus(405);
            $this->asUser($this->owner)->deleteJson($this->path($suffix))->assertStatus(405);
        }
        $this->assertSame('QUEUED', ChallengeSubmission::query()->findOrFail($submission)->status->value);
    }

    public function test_lists_are_paginated_and_never_carry_source_or_definition_bodies(): void
    {
        ChallengeFixtures::manyGaps($this->project);
        foreach (['TYPE_STRUCTURE', 'CODE_HYGIENE', 'FUNCTION_DESIGN'] as $key) {
            $this->assign(['competency_key' => $key]);
        }
        $first = ChallengeInstance::query()->where('competency_key', 'TYPE_STRUCTURE')->firstOrFail();
        $this->submit($first->id, ['source' => "SECRET_SOURCE_MARKER = 1\n"]);

        $page = $this->asUser($this->owner)->getJson($this->path().'?per_page=2')->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
        $this->assertSame(['id', 'type', 'project_id', 'skill_gap_snapshot_id', 'competency_key', 'definition', 'difficulty', 'language', 'status',
            'attempts_used', 'max_attempts', 'last_result', 'created_at', 'closed_at'], array_keys($page->json('data.0')));
        $this->assertStringNotContainsString('starter_code', (string) $page->getContent());

        $submissions = $this->asUser($this->owner)->getJson($this->path("/{$first->id}/submissions"))->assertOk();
        $this->assertStringNotContainsString('SECRET_SOURCE_MARKER', (string) $submissions->getContent());
        $this->assertStringNotContainsString('SECRET_SOURCE_MARKER', (string) $this->asUser($this->owner)->getJson($this->path("/{$first->id}"))->getContent());
        $this->asUser($this->owner)->getJson($this->path().'?per_page=101')->assertStatus(422);
    }

    public function test_the_list_avoids_n_plus_one_queries(): void
    {
        ChallengeFixtures::manyGaps($this->project);
        foreach (['TYPE_STRUCTURE', 'CODE_HYGIENE', 'FUNCTION_DESIGN', 'COMPLEXITY_MANAGEMENT'] as $key) {
            $this->assign(['competency_key' => $key]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->asUser($this->owner)->getJson($this->path())->assertOk();

        $definitionQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'challenge_definitions'));
        $this->assertCount(1, $definitionQueries);
        $this->assertStringNotContainsString('"document"', array_values($definitionQueries)[0]['query']);
    }

    /**
     * Practice never touches the deterministic results.
     */
    public function test_assignment_and_submission_never_change_skill_gaps_or_snapshots(): void
    {
        $gaps = ChallengeFixtures::oneGap($this->project);
        $state = fn (): string => (string) json_encode([
            DB::table('skill_gap_snapshots')->where('id', $gaps->id)->first(),
            DB::table('skill_gap_results')->where('skill_gap_snapshot_id', $gaps->id)->orderBy('position')->get(),
            DB::table('competency_snapshots')->where('id', $gaps->competency_snapshot_id)->first(),
            DB::table('dna_snapshots')->where('id', $gaps->dna_snapshot_id)->first(),
            DB::table('analysis_runs')->where('id', $gaps->analysis_run_id)->first(),
        ]);
        $before = $state();

        $id = $this->assign()->json('data.id');
        $this->evaluate($this->submit($id)->json('data.id'));

        $this->assertSame('PASSED', ChallengeInstance::query()->findOrFail($id)->status->value);
        $this->assertSame($before, $state(), 'a passed challenge changes no gap, competency, DNA or run');
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/skill-gaps/{$gaps->id}")->assertJsonPath('data.status', 'GAPS_IDENTIFIED');
        $this->assertSame(1, SkillGapSnapshot::query()->count());
    }

    public function test_submission_requests_are_rate_limited(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->assign()->json('data.id');

        foreach (range(1, 10) as $i) {
            $this->submit($id);
        }
        $this->submit($id)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_the_status_enum_matches_the_database(): void
    {
        $this->assertSame(['ASSIGNED', 'EVALUATING', 'PASSED', 'FAILED'], array_column(ChallengeStatus::cases(), 'value'));
    }
}
