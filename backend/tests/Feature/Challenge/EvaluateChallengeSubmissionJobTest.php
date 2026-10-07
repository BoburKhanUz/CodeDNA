<?php

declare(strict_types=1);

namespace Tests\Feature\Challenge;

use App\Actions\Challenge\AssignChallenge;
use App\Actions\Challenge\SubmitChallengeSolution;
use App\Enums\Challenge\ChallengeStatus;
use App\Enums\Challenge\SubmissionFailure;
use App\Enums\Challenge\SubmissionStatus;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Models\Project;
use App\Models\User;
use App\Services\Challenge\ChallengeGrader;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use App\Services\Challenge\Evaluator\EvaluationRequest;
use App\Services\Challenge\Evaluator\EvaluatorException;
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
use Tests\Support\ChallengeFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\TestCase;

/**
 * App\Jobs\EvaluateChallengeSubmission with a fake evaluator: the
 * submission and challenge lifecycle, bounded retries that never re-run
 * code, stale-job protection, and safe logs.
 */
final class EvaluateChallengeSubmissionJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logs = [];

    private FakeChallengeEvaluator $evaluator;

    private ChallengeInstance $challenge;

    private const SOURCE = "SOURCE_MARKER = 'never logged'\ndef parse_config(text):\n    return {}\n";

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.challenges.enabled' => true, 'codedna.ai.enabled' => false]);
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
        $this->useEvaluator('pass');
        $this->assign('CODE_HYGIENE');
    }

    /**
     * Assigns a challenge for the competency in a fresh project with gaps in all four.
     */
    private function assign(string $competency): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        ChallengeFixtures::manyGaps($project);
        $this->challenge = app(AssignChallenge::class)->handle($project, $owner, null, $competency)->instance;
    }

    private function useEvaluator(string ...$modes): void
    {
        $this->evaluator = new FakeChallengeEvaluator(...$modes);
        $this->app->instance(ChallengeEvaluator::class, $this->evaluator);
    }

    private function submitted(): ChallengeSubmission
    {
        $challenge = ChallengeInstance::query()->findOrFail($this->challenge->id);

        return app(SubmitChallengeSolution::class)->handle($challenge, User::query()->findOrFail($challenge->user_id), 'python', self::SOURCE, null)->submission;
    }

    private function newJob(ChallengeSubmission $submission): EvaluateChallengeSubmission
    {
        return (new EvaluateChallengeSubmission($submission->id))->withFakeQueueInteractions();
    }

    private function runJob(EvaluateChallengeSubmission $job): EvaluateChallengeSubmission
    {
        app()->call([$job, 'handle']);

        return $job;
    }

    private function fresh(ChallengeSubmission $submission): ChallengeSubmission
    {
        return ChallengeSubmission::query()->findOrFail($submission->id);
    }

    private function challenge(): ChallengeInstance
    {
        return ChallengeInstance::query()->findOrFail($this->challenge->id);
    }

    public function test_a_passing_attempt_passes_the_challenge(): void
    {
        $submission = $this->submitted();

        $this->runJob($this->newJob($submission))->assertNotReleased();

        $stored = $this->fresh($submission);
        $this->assertSame(SubmissionStatus::Passed, $stored->status);
        $this->assertSame(['COMPLETED', '1.0.0', 'python3.11', 42], [$stored->execution_status, $stored->evaluator_version, $stored->runtime, $stored->duration_ms]);
        $this->assertSame((new ChallengeGrader)->fingerprint($stored->evaluation), $stored->evaluation_fingerprint);
        $this->assertSame('PASSED', $stored->evaluation['verdict']);
        $this->assertNull($stored->claim_token);
        $this->assertNotNull($stored->completed_at);
        $challenge = $this->challenge();
        $this->assertSame([ChallengeStatus::Passed, 1, 'PASSED'], [$challenge->status, $challenge->attempts_used, $challenge->last_result]);
        $this->assertNotNull($challenge->closed_at);
        $this->assertSame(1, $this->evaluator->calls);
    }

    /**
     * The evaluator receives inputs only: never expected outputs.
     */
    public function test_the_evaluator_never_receives_expected_outputs(): void
    {
        $this->runJob($this->newJob($this->submitted()));

        $request = $this->evaluator->requests[0]->toArray();
        $this->assertSame(self::SOURCE, $request['source']);
        $this->assertSame('parse_config', $request['entrypoint']);
        $this->assertSame(['v1', 'v2', 'h1', 'h2', 'h3'], array_column($request['cases'], 'id'));
        $this->assertStringNotContainsString('expected', (string) json_encode($request));
        $this->assertStringNotContainsString('"name":"CodeDNA"', (string) json_encode($request), 'not even the visible expected values');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failingAttempts(): iterable
    {
        yield 'wrong values' => ['wrong', 'COMPLETED', 'CODE_HYGIENE'];
        yield 'rules broken' => ['static', 'COMPLETED', 'TYPE_STRUCTURE'];
        yield 'syntax error' => ['syntax', 'SYNTAX_ERROR', 'CODE_HYGIENE'];
        yield 'missing entry point' => ['load_error', 'LOAD_ERROR', 'CODE_HYGIENE'];
        yield 'time limit' => ['timeout', 'TIMEOUT', 'FUNCTION_DESIGN'];
        yield 'crash' => ['crashed', 'CRASHED', 'FUNCTION_DESIGN'];
    }

    #[DataProvider('failingAttempts')]
    public function test_a_failing_attempt_uses_one_attempt_and_reopens_the_challenge(string $mode, string $execution, string $competency): void
    {
        $this->assign($competency);
        $this->useEvaluator($mode);
        $submission = $this->submitted();

        $this->runJob($this->newJob($submission))->assertNotReleased();

        $stored = $this->fresh($submission);
        $this->assertSame([SubmissionStatus::Failed, $execution, 'FAILED'], [$stored->status, $stored->execution_status, $stored->evaluation['verdict']]);
        $this->assertSame([ChallengeStatus::Assigned, 1, 'FAILED'], [$this->challenge()->status, $this->challenge()->attempts_used, $this->challenge()->last_result]);
        $this->assertSame(1, $this->evaluator->calls, 'failing code is never re-run');
    }

    public function test_the_last_failing_attempt_fails_the_challenge(): void
    {
        // The limit is fixed when the challenge is assigned.
        config(['codedna.challenges.max_attempts' => 1]);
        $this->assign('CODE_HYGIENE');
        $this->useEvaluator('wrong');

        $this->runJob($this->newJob($this->submitted()));

        $this->assertSame([ChallengeStatus::Failed, 1, 1], [$this->challenge()->status, $this->challenge()->attempts_used, $this->challenge()->max_attempts]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function evaluationErrors(): iterable
    {
        yield 'interrupted' => ['interrupted', 'EVALUATION_INTERRUPTED'];
        yield 'rejected' => ['rejected', 'EVALUATION_REJECTED'];
        yield 'invalid result' => ['invalid', 'EVALUATION_INVALID'];
        yield 'unexpected error' => ['exception', 'EVALUATION_FAILED'];
    }

    /**
     * An evaluation that could not be completed is ERROR: it consumes no
     * attempt and the challenge accepts the next one.
     */
    #[DataProvider('evaluationErrors')]
    public function test_an_evaluation_error_consumes_no_attempt(string $mode, string $code): void
    {
        $this->useEvaluator($mode);
        $submission = $this->submitted();

        $this->runJob($this->newJob($submission))->assertNotReleased();

        $stored = $this->fresh($submission);
        $this->assertSame([SubmissionStatus::Error, $code, null], [$stored->status, $stored->failure_code, $stored->evaluation]);
        $this->assertSame([ChallengeStatus::Assigned, 0, 'ERROR'], [$this->challenge()->status, $this->challenge()->attempts_used, $this->challenge()->last_result]);
        $this->assertStringNotContainsString('/var/spool', (string) json_encode(array_map(fn (MessageLogged $l) => $l->context, $this->logs)));
    }

    /**
     * A transient failure is retried with backoff; each retry asks the
     * evaluator again for the same submission id (which never runs it twice).
     */
    public function test_transient_evaluator_failures_are_retried_then_end_as_error(): void
    {
        $this->useEvaluator('unavailable');
        $submission = $this->submitted();
        $job = $this->newJob($submission);

        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased(10);
        $this->assertSame([SubmissionStatus::Running, 1], [$this->fresh($submission)->status, $this->fresh($submission)->job_attempts]);
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased(30);
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertNotReleased();

        $this->assertSame([SubmissionStatus::Error, 'EVALUATOR_UNAVAILABLE', 3], [$this->fresh($submission)->status, $this->fresh($submission)->failure_code, $this->fresh($submission)->job_attempts]);
        $this->assertSame([$submission->id, $submission->id, $submission->id], array_map(fn ($r) => $r->submissionId, $this->evaluator->requests));
    }

    public function test_a_still_running_evaluation_is_collected_by_the_retry(): void
    {
        $this->useEvaluator('still_running', 'pass');
        $submission = $this->submitted();
        $job = $this->newJob($submission);

        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased(10);
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertNotReleased();

        $this->assertSame(SubmissionStatus::Passed, $this->fresh($submission)->status);
    }

    public function test_a_duplicate_job_does_not_evaluate_while_a_lease_is_live(): void
    {
        $this->useEvaluator('unavailable', 'pass');
        $submission = $this->submitted();
        $this->runJob($this->newJob($submission))->assertReleased(10);

        $this->runJob($this->newJob($submission))->assertNotReleased();

        $this->assertSame(1, $this->evaluator->calls);
        $this->assertSame(SubmissionStatus::Running, $this->fresh($submission)->status);
    }

    /**
     * Lease takeover: a job whose lease expired loses the submission; its
     * late result is discarded and the job that holds the lease decides.
     */
    public function test_a_stale_job_cannot_write_its_result_after_a_takeover(): void
    {
        $submission = $this->submitted();
        $stale = new EvaluateChallengeSubmission($submission->id);
        $this->app->instance(ChallengeEvaluator::class, new class($submission->id) implements ChallengeEvaluator
        {
            public function __construct(private readonly string $id) {}

            public function name(): string
            {
                return 'fake';
            }

            public function available(): bool
            {
                return true;
            }

            public function evaluate(EvaluationRequest $request): array
            {
                // While this (stale) job waits, its lease expires and another job takes over.
                DB::table('challenge_submissions')->where('id', $this->id)->update(['claim_token' => (string) Str::uuid()]);

                return (new FakeChallengeEvaluator('pass'))->evaluate($request);
            }
        });

        app()->call([$stale, 'handle']);

        $stored = $this->fresh($submission);
        $this->assertSame([SubmissionStatus::Running, null], [$stored->status, $stored->evaluation]);
        $this->assertSame([ChallengeStatus::Evaluating, 0], [$this->challenge()->status, $this->challenge()->attempts_used]);
        $this->assertContains('challenge.result_ignored', array_map(fn (MessageLogged $l) => $l->message, $this->logs));
    }

    /**
     * A stale job that meets a transient evaluator failure neither extends
     * the new owner's lease nor schedules a retry.
     */
    public function test_a_stale_job_does_not_retry_after_a_takeover(): void
    {
        $submission = $this->submitted();
        $takeover = new class($submission->id) implements ChallengeEvaluator
        {
            public ?string $lease = null;

            public function __construct(private readonly string $id) {}

            public function name(): string
            {
                return 'fake';
            }

            public function available(): bool
            {
                return true;
            }

            public function evaluate(EvaluationRequest $request): array
            {
                DB::table('challenge_submissions')->where('id', $this->id)->update(['claim_token' => (string) Str::uuid()]);
                $this->lease = (string) DB::table('challenge_submissions')->where('id', $this->id)->value('lease_expires_at');

                throw new EvaluatorException(SubmissionFailure::EvaluatorUnavailable, true, 'not_claimed');
            }
        };
        $this->app->instance(ChallengeEvaluator::class, $takeover);

        $this->runJob($this->newJob($submission))->assertNotReleased();

        $this->assertSame($takeover->lease, (string) DB::table('challenge_submissions')->where('id', $submission->id)->value('lease_expires_at'));
        $this->assertSame(SubmissionStatus::Running, $this->fresh($submission)->status);
    }

    /**
     * Only graded execution statuses are graded; anything else is an
     * evaluation error that consumes no attempt.
     */
    public function test_an_ungraded_status_is_an_invalid_evaluation(): void
    {
        $submission = $this->submitted();
        $this->app->instance(ChallengeEvaluator::class, new class implements ChallengeEvaluator
        {
            public function name(): string
            {
                return 'fake';
            }

            public function available(): bool
            {
                return true;
            }

            public function evaluate(EvaluationRequest $request): array
            {
                return ['status' => 'INTERRUPTED'] + (new FakeChallengeEvaluator('pass'))->evaluate($request);
            }
        });

        $this->runJob($this->newJob($submission));

        $stored = $this->fresh($submission);
        $this->assertSame([SubmissionStatus::Error, 'EVALUATION_INVALID', 'status'], [$stored->status, $stored->failure_code, $stored->failure_detail]);
        $this->assertSame([ChallengeStatus::Assigned, 0], [$this->challenge()->status, $this->challenge()->attempts_used]);
    }

    public function test_an_expired_lease_is_taken_over(): void
    {
        $this->useEvaluator('unavailable', 'pass');
        $submission = $this->submitted();
        $this->runJob($this->newJob($submission));
        DB::table('challenge_submissions')->where('id', $submission->id)->update(['lease_expires_at' => Carbon::now()->subSecond()]);

        $this->runJob($this->newJob($submission));

        $this->assertSame([SubmissionStatus::Passed, 2], [$this->fresh($submission)->status, $this->evaluator->calls]);
    }

    public function test_terminal_submissions_are_left_alone(): void
    {
        $submission = $this->submitted();
        $this->runJob($this->newJob($submission));
        $before = $this->fresh($submission)->getAttributes();

        $this->runJob($this->newJob($submission));

        $this->assertSame(1, $this->evaluator->calls);
        $this->assertSame($before, $this->fresh($submission)->getAttributes());
    }

    public function test_exhausted_job_attempts_end_the_attempt_without_a_call(): void
    {
        $submission = $this->submitted();
        DB::table('challenge_submissions')->where('id', $submission->id)->update(['job_attempts' => 3]);

        $this->runJob($this->newJob($submission));

        $this->assertSame([SubmissionStatus::Error, 'EVALUATION_FAILED', 'attempts_exhausted'], [$this->fresh($submission)->status, $this->fresh($submission)->failure_code, $this->fresh($submission)->failure_detail]);
        $this->assertSame([0, ChallengeStatus::Assigned], [$this->evaluator->calls, $this->challenge()->status]);
    }

    public function test_a_changed_definition_is_never_evaluated(): void
    {
        $submission = $this->submitted();
        DB::statement('ALTER TABLE challenge_definitions DISABLE TRIGGER challenge_definitions_immutable');
        DB::statement("UPDATE challenge_definitions SET document = jsonb_set(document, '{cases,0,expected}', '\"tampered\"')");
        DB::statement('ALTER TABLE challenge_definitions ENABLE TRIGGER challenge_definitions_immutable');

        $this->runJob($this->newJob($submission));

        $this->assertSame([SubmissionStatus::Error, 'EVALUATION_INVALID', 'definition_changed'], [$this->fresh($submission)->status, $this->fresh($submission)->failure_code, $this->fresh($submission)->failure_detail]);
        $this->assertSame(0, $this->evaluator->calls);
    }

    public function test_the_failed_hook_never_leaves_an_attempt_running(): void
    {
        $this->useEvaluator('unavailable');
        $submission = $this->submitted();
        $job = $this->newJob($submission);
        $this->runJob($job);

        $job->failed(new TimeoutExceededException('worker timeout'));

        $this->assertSame([SubmissionStatus::Error, 'EVALUATION_TIMEOUT', 'job_timeout'], [$this->fresh($submission)->status, $this->fresh($submission)->failure_code, $this->fresh($submission)->failure_detail]);
        $this->assertSame(ChallengeStatus::Assigned, $this->challenge()->status);
        $job->failed(new RuntimeException('again'));
        $this->assertSame('EVALUATION_TIMEOUT', $this->fresh($submission)->failure_code, 'a terminal attempt is not changed');
    }

    public function test_the_same_submission_and_observations_give_the_same_evaluation(): void
    {
        $this->assign('TYPE_STRUCTURE');
        $this->useEvaluator('static', 'static');
        $first = $this->submitted();
        $this->runJob($this->newJob($first));
        $second = $this->submitted();
        $this->runJob($this->newJob($second));

        $this->assertSame($this->fresh($first)->evaluation_fingerprint, $this->fresh($second)->evaluation_fingerprint);
        $this->assertEquals($this->fresh($first)->evaluation, $this->fresh($second)->evaluation);
    }

    /**
     * Logs carry identifiers, statuses and codes; never source, tests or
     * evaluator internals.
     */
    public function test_logs_never_contain_source_or_test_data(): void
    {
        $this->useEvaluator('unavailable', 'wrong');
        $submission = $this->submitted();
        $job = $this->newJob($submission);
        $this->runJob((clone $job)->withFakeQueueInteractions());
        $this->runJob((clone $job)->withFakeQueueInteractions());

        $logged = (string) json_encode(array_map(fn (MessageLogged $log) => [$log->message, $log->context], $this->logs));
        $this->assertStringContainsString($submission->id, $logged);
        foreach (['SOURCE_MARKER', 'parse_config', 'expected', 'CodeDNA', 'broken line', '/var/spool'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $logged);
        }
        foreach ($this->logs as $log) {
            $this->assertSame([], array_diff(array_keys($log->context), [
                'submission_id', 'challenge_id', 'project_id', 'attempt', 'job_attempt', 'status', 'duration_ms', 'error_code', 'reason',
                'retryable', 'retry_in_seconds', 'challenge_status', 'execution_status', 'evaluation_version', 'source_bytes', 'requested_by',
                'request_id', 'exception', 'skill_gap_snapshot_id', 'definition', 'competency_key', 'rule',
            ]), "unexpected log context in {$log->message}");
        }
    }
}
