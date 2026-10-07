<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Actions\Assessment\RequestAssessment;
use App\Enums\Assessment\AssessmentFailure;
use App\Enums\Assessment\AssessmentStatus;
use App\Jobs\GenerateAssessment;
use App\Models\AiAssessment;
use App\Models\AnalysisRun;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Models\Project;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentPrompt;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Assessment\Provider\AiProviderResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AssessmentFixtures;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * App\Jobs\GenerateAssessment with a scripted provider: one call per
 * attempt, strict validation, bounded retries for transient failures only,
 * and no effect at all on the deterministic snapshots.
 */
final class GenerateAssessmentJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logs = [];

    private ScriptedAiProvider $provider;

    private SkillGapSnapshot $gaps;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => true]);
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
        $this->gaps = AssessmentFixtures::skillGaps(Project::factory()->for(User::factory())->create());
    }

    private function queued(string ...$modes): AiAssessment
    {
        $this->provider = new ScriptedAiProvider(...$modes);
        $this->app->instance(AiProvider::class, $this->provider);
        $project = Project::query()->findOrFail($this->gaps->project_id);

        return app(RequestAssessment::class)->handle($project, $project->user()->firstOrFail(), null)->assessment;
    }

    private function newJob(AiAssessment $assessment): GenerateAssessment
    {
        return (new GenerateAssessment($assessment->id))->withFakeQueueInteractions();
    }

    private function runJob(GenerateAssessment $job): GenerateAssessment
    {
        app()->call([$job, 'handle']);

        return $job;
    }

    private function fresh(AiAssessment $assessment): AiAssessment
    {
        return AiAssessment::query()->findOrFail($assessment->id);
    }

    public function test_a_valid_response_is_stored_as_succeeded(): void
    {
        $assessment = $this->queued('valid');

        $this->runJob($this->newJob($assessment))->assertNotReleased();

        $stored = $this->fresh($assessment);
        $this->assertSame(AssessmentStatus::Succeeded, $stored->status);
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame(1, $stored->attempts);
        $this->assertSame('assessment/v1', $stored->output['schema_version']);
        $this->assertSame(CanonicalJson::hash(json_decode((string) json_encode($stored->output), false)), $stored->output_fingerprint);
        $this->assertEquals(['served_model' => 'scripted-model-1-2026', 'response_id' => 'resp_test_1', 'input_tokens' => 1200, 'output_tokens' => 300], $stored->provider_metadata);
        $this->assertNull($stored->failure_code);
        $this->assertNull($stored->claim_token);
        $this->assertNull($stored->lease_expires_at);
        $this->assertNotNull($stored->started_at);
        $this->assertNotNull($stored->completed_at);
    }

    public function test_one_fenced_json_response_is_accepted(): void
    {
        $assessment = $this->queued('fenced');

        $this->runJob($this->newJob($assessment));

        $this->assertSame(AssessmentStatus::Succeeded, $this->fresh($assessment)->status);
    }

    /**
     * The prompt the provider received: server-owned instructions, the
     * stored input between the delimiters, and nothing else.
     */
    public function test_the_provider_receives_the_stored_input_and_the_server_prompt(): void
    {
        $assessment = $this->queued('valid');

        $this->runJob($this->newJob($assessment));

        $prompt = $this->provider->prompts[0];
        $this->assertSame($assessment->prompt_fingerprint, $prompt->fingerprint);
        $payload = CanonicalJson::encode(json_decode((string) json_encode($assessment->input['payload']), false));
        $this->assertStringContainsString("<<<BEGIN_UNTRUSTED_EVIDENCE_JSON>>>\n{$payload}\n<<<END_UNTRUSTED_EVIDENCE_JSON>>>", $prompt->user);
    }

    /**
     * @return iterable<string, array{string, AssessmentFailure, string}>
     */
    public static function rejectedOutputs(): iterable
    {
        yield 'malformed JSON' => ['malformed', AssessmentFailure::InvalidOutput, 'json'];
        yield 'invented evidence reference' => ['invalid_ref', AssessmentFailure::InvalidOutput, 'evidence_ref_unknown'];
        yield 'score and confidence fields' => ['forbidden_score', AssessmentFailure::InvalidOutput, 'forbidden_field'];
        yield 'obeyed an injected instruction' => ['injection', AssessmentFailure::InvalidOutput, 'numeric_score'];
        yield 'unsupported claims' => ['unsupported_claim', AssessmentFailure::InvalidOutput, 'unsupported_claim'];
        yield 'oversized' => ['oversized', AssessmentFailure::OutputTooLarge, 'size'];
        yield 'refusal' => ['refusal', AssessmentFailure::ProviderRejected, 'refusal'];
        yield 'credentials rejected' => ['auth_failed', AssessmentFailure::ProviderAuthFailed, 'auth_rejected'];
    }

    /**
     * Invalid output is discarded, never repaired, never retried, and never
     * replaced by a fallback assessment.
     */
    #[DataProvider('rejectedOutputs')]
    public function test_unusable_responses_fail_without_retry_or_fallback(string $mode, AssessmentFailure $failure, string $detail): void
    {
        $assessment = $this->queued($mode, 'valid');

        $this->runJob($this->newJob($assessment))->assertNotReleased();

        $stored = $this->fresh($assessment);
        $this->assertSame(AssessmentStatus::Failed, $stored->status);
        $this->assertSame([$failure->value, $detail], [$stored->failure_code, $stored->failure_detail]);
        $this->assertNull($stored->output);
        $this->assertNull($stored->output_fingerprint);
        $this->assertSame(1, $this->provider->calls);

        // A redelivered job does nothing: the failure is final for this row.
        $this->runJob($this->newJob($assessment));
        $this->assertSame(1, $this->provider->calls);
    }

    /**
     * @return iterable<string, array{string, AssessmentFailure, list<int>}>
     */
    public static function transientFailures(): iterable
    {
        yield 'timeout' => ['timeout', AssessmentFailure::ProviderTimeout, [20, 60]];
        // Retry-After 45 is honoured over the 20 s backoff.
        yield 'rate limited' => ['rate_limited', AssessmentFailure::ProviderRateLimited, [45, 60]];
        yield 'server error' => ['server_error', AssessmentFailure::ProviderUnavailable, [20, 60]];
    }

    /**
     * @param  list<int>  $delays
     */
    #[DataProvider('transientFailures')]
    public function test_transient_failures_are_retried_with_backoff_up_to_the_limit(string $mode, AssessmentFailure $failure, array $delays): void
    {
        $assessment = $this->queued($mode);
        $job = $this->newJob($assessment);

        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased($delays[0]);
        $this->assertSame([AssessmentStatus::Running, 1], [$this->fresh($assessment)->status, $this->fresh($assessment)->attempts]);
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased($delays[1]);
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertNotReleased();

        $stored = $this->fresh($assessment);
        $this->assertSame([AssessmentStatus::Failed, $failure->value, 3], [$stored->status, $stored->failure_code, $stored->attempts]);
        $this->assertSame(3, $this->provider->calls);
    }

    public function test_a_transient_failure_followed_by_success_succeeds(): void
    {
        $assessment = $this->queued('server_error', 'valid');
        $job = $this->newJob($assessment);

        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased(20);
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertNotReleased();

        $this->assertSame([AssessmentStatus::Succeeded, 2], [$this->fresh($assessment)->status, $this->fresh($assessment)->attempts]);
    }

    public function test_attempts_are_bounded_across_redeliveries(): void
    {
        config(['codedna.ai.max_attempts' => 1]);
        $assessment = $this->queued('timeout');

        $this->runJob($this->newJob($assessment))->assertNotReleased();

        $this->assertSame([AssessmentStatus::Failed, 'PROVIDER_TIMEOUT'], [$this->fresh($assessment)->status, $this->fresh($assessment)->failure_code]);
        $this->assertSame(1, $this->provider->calls);
    }

    public function test_an_exhausted_assessment_is_failed_without_a_call(): void
    {
        $assessment = $this->queued('valid');
        DB::table('ai_assessments')->where('id', $assessment->id)->update(['attempts' => 3]);

        $this->runJob($this->newJob($assessment));

        $this->assertSame(['FAILED', 'PROVIDER_UNAVAILABLE', 'attempts_exhausted'], [$this->fresh($assessment)->status->value, $this->fresh($assessment)->failure_code, $this->fresh($assessment)->failure_detail]);
        $this->assertSame(0, $this->provider->calls);
    }

    /**
     * Two jobs for one assessment: the second sees a live lease and makes no
     * provider call.
     */
    public function test_a_duplicate_job_does_not_call_the_provider_while_a_lease_is_live(): void
    {
        $assessment = $this->queued('timeout', 'valid');
        $this->runJob($this->newJob($assessment))->assertReleased(20);

        $this->runJob($this->newJob($assessment))->assertNotReleased();

        $this->assertSame(1, $this->provider->calls);
        $this->assertSame(AssessmentStatus::Running, $this->fresh($assessment)->status);
    }

    public function test_an_expired_lease_is_taken_over(): void
    {
        $assessment = $this->queued('timeout', 'valid');
        $this->runJob($this->newJob($assessment));
        DB::table('ai_assessments')->where('id', $assessment->id)->update(['lease_expires_at' => Carbon::now()->subSecond()]);

        $this->runJob($this->newJob($assessment));

        $this->assertSame([AssessmentStatus::Succeeded, 2], [$this->fresh($assessment)->status, $this->provider->calls]);
    }

    /**
     * The lease was taken over while this job waited for the provider (for
     * example after a worker stall): its late result is discarded, and the
     * job that holds the lease decides.
     */
    public function test_a_result_from_a_job_that_lost_its_lease_is_ignored(): void
    {
        $assessment = $this->queued('valid');
        $id = $assessment->id;
        $this->app->instance(AiProvider::class, new class($id) implements AiProvider
        {
            public function __construct(private readonly string $id) {}

            public function name(): string
            {
                return 'scripted';
            }

            public function model(): string
            {
                return 'scripted-model-1';
            }

            public function generateAssessment(AssessmentInput $input, AssessmentPrompt $prompt): AiProviderResponse
            {
                DB::table('ai_assessments')->where('id', $this->id)->update(['claim_token' => (string) Str::uuid()]);

                return (new ScriptedAiProvider('valid'))->generateAssessment($input, $prompt);
            }
        });

        $this->runJob($this->newJob($assessment));

        $stored = $this->fresh($assessment);
        $this->assertSame(AssessmentStatus::Running, $stored->status);
        $this->assertNull($stored->output);
        $this->assertStringContainsString('assessment.result_ignored', (string) json_encode(array_map(fn (MessageLogged $l) => $l->message, $this->logs)));
    }

    public function test_terminal_assessments_are_left_alone(): void
    {
        $assessment = $this->queued('valid');
        $this->runJob($this->newJob($assessment));
        $before = $this->fresh($assessment)->getAttributes();

        $this->runJob($this->newJob($assessment));

        $this->assertSame(1, $this->provider->calls);
        $this->assertSame($before, $this->fresh($assessment)->getAttributes());
    }

    /**
     * A configuration change between request and job: the recorded identity
     * can no longer be produced, so the assessment fails instead of
     * silently using another model.
     */
    public function test_a_changed_model_fails_the_assessment_without_a_call(): void
    {
        $assessment = $this->queued('valid');
        $this->provider->modelName = 'another-model';

        $this->runJob($this->newJob($assessment));

        $this->assertSame(['FAILED', 'ASSESSMENT_FAILED', 'configuration_changed'], [$this->fresh($assessment)->status->value, $this->fresh($assessment)->failure_code, $this->fresh($assessment)->failure_detail]);
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_turning_ai_off_stops_queued_assessments_without_a_call(): void
    {
        $assessment = $this->queued('valid');
        config(['codedna.ai.enabled' => false]);

        $this->runJob($this->newJob($assessment));

        $this->assertSame(['FAILED', 'ASSESSMENT_FAILED', 'ai_disabled'], [$this->fresh($assessment)->status->value, $this->fresh($assessment)->failure_code, $this->fresh($assessment)->failure_detail]);
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_changed_evidence_fails_the_assessment_without_a_call(): void
    {
        $assessment = $this->queued('valid');
        $competency = CompetencySnapshot::query()->findOrFail($this->gaps->competency_snapshot_id);
        $competencies = $competency->competencies;
        $competencies[1]['evidence_quality'] = '0.8000';
        DB::table('competency_snapshots')->where('id', $competency->id)->update(['competencies' => json_encode($competencies)]);
        $this->runJob($this->newJob($assessment));
        $this->assertSame(['FAILED', 'EVIDENCE_CHANGED'], [$this->fresh($assessment)->status->value, $this->fresh($assessment)->failure_code]);

        $second = $this->queued('valid');
        DB::table('skill_gap_snapshots')->where('id', $this->gaps->id)->update(['specification_fingerprint' => str_repeat('0', 64)]);
        $this->runJob($this->newJob($second));
        $this->assertSame(['FAILED', 'EVIDENCE_INVALID'], [$this->fresh($second)->status->value, $this->fresh($second)->failure_code]);
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_an_input_over_the_limit_fails_without_a_call(): void
    {
        $assessment = $this->queued('valid');
        config(['codedna.ai.max_input_bytes' => 1024]);

        $this->runJob($this->newJob($assessment));

        $this->assertSame(['FAILED', 'INPUT_TOO_LARGE'], [$this->fresh($assessment)->status->value, $this->fresh($assessment)->failure_code]);
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_an_unexpected_provider_error_fails_safely(): void
    {
        $assessment = $this->queued('valid');
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function name(): string
            {
                return 'scripted';
            }

            public function model(): string
            {
                return 'scripted-model-1';
            }

            public function generateAssessment(AssessmentInput $input, AssessmentPrompt $prompt): AiProviderResponse
            {
                throw new RuntimeException('provider SDK exploded with sk-secret');
            }
        });

        $this->runJob($this->newJob($assessment))->assertNotReleased();

        $this->assertSame(['FAILED', 'ASSESSMENT_FAILED', 'provider_error'], [$this->fresh($assessment)->status->value, $this->fresh($assessment)->failure_code, $this->fresh($assessment)->failure_detail]);
        $this->assertStringNotContainsString('sk-secret', (string) json_encode(array_map(fn (MessageLogged $l) => [$l->message, $l->context], $this->logs)));
    }

    public function test_the_failed_hook_never_leaves_an_assessment_running(): void
    {
        $assessment = $this->queued('timeout');
        $job = $this->newJob($assessment);
        $this->runJob($job);

        $job->failed(new TimeoutExceededException('worker timeout'));

        $this->assertSame(['FAILED', 'PROVIDER_TIMEOUT', 'job_timeout'], [$this->fresh($assessment)->status->value, $this->fresh($assessment)->failure_code, $this->fresh($assessment)->failure_detail]);
        $job->failed(new RuntimeException('again'));
        $this->assertSame('PROVIDER_TIMEOUT', $this->fresh($assessment)->failure_code, 'a terminal assessment is not changed');
    }

    /**
     * An AI failure never touches the run, DNA, competency or skill gap
     * snapshots; neither does a success.
     */
    public function test_deterministic_snapshots_are_never_changed(): void
    {
        $snapshot = fn (): array => [
            AnalysisRun::query()->findOrFail($this->gaps->analysis_run_id)->getAttributes(),
            DnaSnapshot::query()->findOrFail($this->gaps->dna_snapshot_id)->getAttributes(),
            CompetencySnapshot::query()->findOrFail($this->gaps->competency_snapshot_id)->getAttributes(),
            SkillGapSnapshot::query()->findOrFail($this->gaps->id)->getAttributes(),
            SkillGapResult::query()->where('skill_gap_snapshot_id', $this->gaps->id)->orderBy('position')->get()->map->getAttributes()->all(),
        ];
        $before = $snapshot();

        $this->runJob($this->newJob($this->queued('injection')));
        $this->runJob($this->newJob($this->queued('auth_failed')));
        $this->provider->modelName = 'other';
        $this->runJob($this->newJob($this->queued('valid')));

        $this->assertSame($before, $snapshot());
        $this->assertSame(['FAILED', 'FAILED', 'SUCCEEDED'], AiAssessment::query()->orderBy('created_at')->orderBy('id')->pluck('status')->map->value->all());
    }

    /**
     * Logs carry identifiers, statuses, durations and codes; never the
     * prompt, the input, the response or a key.
     */
    public function test_logs_never_contain_prompts_inputs_responses_or_keys(): void
    {
        config(['codedna.ai.api_key' => 'configured-provider-key']);
        $assessment = $this->queued('injection');
        $this->runJob($this->newJob($assessment));
        $this->runJob($this->newJob($this->queued('rate_limited')));

        $logged = (string) json_encode(array_map(fn (MessageLogged $log) => [$log->message, $log->context], $this->logs));
        $this->assertStringContainsString($assessment->id, $logged);
        $this->assertStringContainsString('assessment.failed', $logged);
        $this->assertStringContainsString('assessment.retrying', $logged);
        foreach (['configured-provider-key', 'BEGIN_UNTRUSTED', 'is DATA', '100/100', 'Ignore previous', 'COMPLEXITY_MANAGEMENT', '0.6000', 'Authorization', 'resp_test_1'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $logged);
        }
        foreach ($this->logs as $log) {
            $this->assertSame([], array_diff(array_keys($log->context), [
                'assessment_id', 'project_id', 'input_fingerprint', 'provider', 'model', 'attempt', 'status', 'duration_ms',
                'error_code', 'http_status', 'reason', 'retryable', 'retry_in_seconds', 'requested_by', 'request_id', 'exception',
            ]), "unexpected log context in {$log->message}");
        }
    }
}
