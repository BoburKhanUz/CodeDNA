<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Models\AiAssessment;
use App\Models\AnalysisRun;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AssessmentFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * Phase 22 failure matrix: the queue (Redis) refuses a job at dispatch time.
 * The record that was just created must not stay QUEUED forever: it ends
 * with a safe failure code, costs the developer nothing (no challenge
 * attempt), and a new request after the outage works.
 */
final class QueueOutageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Dispatcher $working;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => true, 'codedna.challenges.enabled' => true]);
        $this->app->instance(AiProvider::class, new ScriptedAiProvider('valid'));
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator('pass'));
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
        $this->working = $this->app->make(Dispatcher::class);
    }

    private function queueDown(): void
    {
        $this->app->instance(Dispatcher::class, new class($this->app) extends BusDispatcher
        {
            public function dispatch($command): mixed
            {
                throw new RuntimeException('Connection refused [tcp://redis:6379]');
            }
        });
    }

    private function queueUp(): void
    {
        $this->app->instance(Dispatcher::class, $this->working);
    }

    public function test_an_analysis_that_cannot_be_queued_fails_and_can_be_retried(): void
    {
        $snapshot = SourceSnapshot::factory()->for($this->project)->create();
        $url = "/api/v1/projects/{$this->project->id}/analyses";
        $this->queueDown();

        $response = $this->asUser($this->owner)->postJson($url, ['source_snapshot_id' => $snapshot->id]);

        $response->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.failure.code', 'DISPATCH_FAILED');
        $this->assertStringNotContainsString('redis', (string) $response->getContent());
        $this->assertSame(['FAILED', 'DISPATCH_FAILED'], [AnalysisRun::query()->sole()->status->value, AnalysisRun::query()->sole()->failure_code]);

        $this->queueUp();
        $this->asUser($this->owner)->postJson($url, ['source_snapshot_id' => $snapshot->id])->assertStatus(202)->assertJsonPath('data.status', 'QUEUED');
        $this->assertSame(2, AnalysisRun::query()->count());
    }

    public function test_an_assessment_that_cannot_be_queued_fails_and_can_be_requested_again(): void
    {
        AssessmentFixtures::skillGaps($this->project);
        $url = "/api/v1/projects/{$this->project->id}/assessments";
        $this->queueDown();

        $response = $this->asUser($this->owner)->postJson($url);

        $this->assertStringNotContainsString('redis', (string) $response->getContent());
        $failed = AiAssessment::query()->sole();
        $this->assertSame(['FAILED', 'ASSESSMENT_FAILED', 'dispatch_failed'], [$failed->status->value, $failed->failure_code, $failed->failure_detail]);

        $this->queueUp();
        $this->asUser($this->owner)->postJson($url)->assertStatus(202)->assertJsonPath('data.status', 'QUEUED');
    }

    public function test_a_submission_that_cannot_be_queued_ends_as_an_error_without_using_an_attempt(): void
    {
        $gaps = AssessmentFixtures::skillGaps($this->project);
        $base = "/api/v1/projects/{$this->project->id}/challenges";
        $challenge = $this->asUser($this->owner)->postJson($base, ['skill_gap_snapshot_id' => $gaps->id])->assertCreated()->json('data.id');
        $this->queueDown();

        $response = $this->asUser($this->owner)->postJson("{$base}/{$challenge}/submissions", ['language' => 'python', 'source' => "def f():\n    return 1\n"]);

        $this->assertStringNotContainsString('redis', (string) $response->getContent());
        $submission = ChallengeSubmission::query()->sole();
        $this->assertSame(['ERROR', 'EVALUATION_FAILED', 'dispatch_failed'], [$submission->status->value, $submission->failure_code, $submission->failure_detail]);
        $instance = ChallengeInstance::query()->findOrFail($challenge);
        $this->assertSame(['ASSIGNED', 0], [$instance->status->value, $instance->attempts_used]);

        $this->queueUp();
        $this->asUser($this->owner)->postJson("{$base}/{$challenge}/submissions", ['language' => 'python', 'source' => "def f():\n    return 2\n"])
            ->assertStatus(202)->assertJsonPath('data.status', 'QUEUED');
    }
}
