<?php

declare(strict_types=1);

namespace Tests\Feature\Challenge;

use App\Enums\Challenge\SubmissionFailure;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Models\Project;
use App\Models\User;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use App\Services\Challenge\Evaluator\EvaluationRequest;
use App\Services\Challenge\Evaluator\EvaluatorException;
use App\Services\Challenge\Evaluator\SpoolChallengeEvaluator;
use App\Services\Challenge\Evaluator\UnavailableChallengeEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\ChallengeFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\TestCase;

/**
 * The V1.0.0 configuration (CHALLENGE_ENABLED=false, CHALLENGE_EVALUATOR=none)
 * with the evaluator the container really binds, not a test double: nothing
 * is recorded, queued or written to the spool, and nothing can execute.
 */
final class ChallengeEvaluatorBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->spool = sys_get_temp_dir().'/codedna-spool-'.bin2hex(random_bytes(6));
        mkdir($this->spool);
        config(['codedna.ai.enabled' => false, 'codedna.challenges.spool_path' => $this->spool]);
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
    }

    protected function tearDown(): void
    {
        @rmdir($this->spool);
        parent::tearDown();
    }

    /** @return TestResponse<JsonResponse> */
    private function assign(): TestResponse
    {
        return $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/challenges", []);
    }

    /** @return TestResponse<JsonResponse> */
    private function submit(string $challengeId): TestResponse
    {
        return $this->asUser($this->owner)->postJson(
            "/api/v1/projects/{$this->project->id}/challenges/{$challengeId}/submissions",
            ['language' => 'python', 'source' => "def parse_config(text):\n    return {}\n"],
        );
    }

    private function assertNothingRecordedOrQueued(): void
    {
        $this->assertSame(0, ChallengeSubmission::query()->count());
        Queue::assertNotPushed(EvaluateChallengeSubmission::class);
        $this->assertSame([], array_values(array_diff((array) scandir($this->spool), ['.', '..'])), 'nothing is written to the spool');
    }

    public function test_the_none_evaluator_is_bound_and_can_never_execute(): void
    {
        config(['codedna.challenges.evaluator' => 'none']);
        $evaluator = app(ChallengeEvaluator::class);

        $this->assertInstanceOf(UnavailableChallengeEvaluator::class, $evaluator);
        $this->assertFalse($evaluator->available());
        try {
            $evaluator->evaluate(new EvaluationRequest('01k6p0a1b2c3d4e5f6g7h8j9km', 'python', 'parse_config', 'import os', [['id' => 'v1', 'args' => ['']]]));
            $this->fail('the none evaluator must refuse');
        } catch (EvaluatorException $e) {
            $this->assertSame([SubmissionFailure::EvaluatorUnavailable, false, 'not_configured'], [$e->failure, $e->retryable, $e->detail]);
        }

        config(['codedna.challenges.evaluator' => 'spool']);
        $this->assertInstanceOf(SpoolChallengeEvaluator::class, app(ChallengeEvaluator::class));
    }

    public function test_the_status_reports_challenges_off_in_the_v1_configuration(): void
    {
        config(['codedna.challenges.enabled' => false, 'codedna.challenges.evaluator' => 'none']);
        $this->getJson('/api/v1/challenges/status')->assertUnauthorized();

        $this->asUser($this->owner)->getJson('/api/v1/challenges/status')
            ->assertOk()->assertExactJson(['data' => ['enabled' => false, 'evaluation_available' => false]]);
    }

    public function test_the_status_reports_enabled_and_evaluation_availability_separately(): void
    {
        config(['codedna.challenges.enabled' => true, 'codedna.challenges.evaluator' => 'none']);
        $this->asUser($this->owner)->getJson('/api/v1/challenges/status')
            ->assertOk()->assertExactJson(['data' => ['enabled' => true, 'evaluation_available' => false]]);

        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator('pass'));
        $this->asUser($this->owner)->getJson('/api/v1/challenges/status')
            ->assertOk()->assertExactJson(['data' => ['enabled' => true, 'evaluation_available' => true]]);

        // A working evaluator never makes a disabled feature available.
        config(['codedna.challenges.enabled' => false]);
        $this->asUser($this->owner)->getJson('/api/v1/challenges/status')
            ->assertOk()->assertExactJson(['data' => ['enabled' => false, 'evaluation_available' => false]]);
    }

    public function test_the_v1_configuration_refuses_challenges_and_records_nothing(): void
    {
        ChallengeFixtures::oneGap($this->project);
        // An instance assigned before challenges were turned off.
        config(['codedna.challenges.enabled' => true, 'codedna.challenges.evaluator' => 'none']);
        $id = $this->assign()->assertCreated()->json('data.id');

        config(['codedna.challenges.enabled' => false, 'codedna.challenges.evaluator' => 'none']);

        $this->assign()->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGES_DISABLED');
        $this->assertSame(1, ChallengeInstance::query()->count(), 'no new assignment');
        $this->submit($id)->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGES_DISABLED');
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/challenges/{$id}")
            ->assertOk()->assertJsonPath('data.evaluation_available', false);
        $this->assertNothingRecordedOrQueued();
    }

    public function test_without_an_evaluator_a_submission_is_refused_before_anything_is_recorded(): void
    {
        ChallengeFixtures::oneGap($this->project);
        config(['codedna.challenges.enabled' => true, 'codedna.challenges.evaluator' => 'none']);
        $id = $this->assign()->assertCreated()->json('data.id');

        $this->submit($id)->assertStatus(409)->assertJsonPath('error.code', 'CHALLENGE_EVALUATION_UNAVAILABLE');

        $this->assertSame('ASSIGNED', ChallengeInstance::query()->findOrFail($id)->status->value);
        $this->assertNothingRecordedOrQueued();
    }
}
