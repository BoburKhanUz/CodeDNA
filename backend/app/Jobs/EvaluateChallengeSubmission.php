<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Challenge\ChallengeStatus;
use App\Enums\Challenge\SubmissionFailure;
use App\Enums\Challenge\SubmissionStatus;
use App\Models\ChallengeDefinition;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Services\Challenge\ChallengeGrader;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use App\Services\Challenge\Evaluator\EvaluationRequest;
use App\Services\Challenge\Evaluator\EvaluatorException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Evaluates one challenge submission (docs/architecture/coding-challenges-v1.md#evaluation).
 * The payload is the submission ID only; the source is read from the
 * database and sent to the isolated evaluator, never run here.
 *
 * 1. Claim the submission under its row lock (QUEUED -> RUNNING, resume an
 *    own lease, take over an expired one); a terminal submission or one
 *    leased by another live job is left alone.
 * 2. Check that the stored definition still has the fingerprints the
 *    submission was recorded with.
 * 3. Ask the evaluator. It is keyed by submission ID and executes a
 *    submission at most once, so a retry of this job never runs the code a
 *    second time; it collects the result instead.
 * 4. Grade the observations (ChallengeGrader) and store the verdict with
 *    the challenge's new state in one transaction, only while this job
 *    still holds the lease: a stale job's result is discarded.
 *
 * Transient evaluator failures are retried with backoff, up to
 * codedna.challenges.max_job_attempts; anything else ends the attempt as
 * ERROR, which consumes no graded attempt. Nothing here touches DNA,
 * competency or skill gap data.
 */
final class EvaluateChallengeSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public readonly string $claimToken;

    public function __construct(public readonly string $submissionId)
    {
        $this->claimToken = (string) Str::uuid();
        $this->onConnection((string) config('codedna.challenges.queue_connection'));
        $this->onQueue((string) config('codedna.challenges.queue'));
        $this->tries = (int) config('codedna.challenges.max_job_attempts');
        $this->timeout = (int) config('codedna.challenges.job_timeout_seconds');
    }

    public function handle(ConnectionInterface $db, ChallengeEvaluator $evaluator, ChallengeGrader $grader): void
    {
        $submission = $db->transaction(fn (): ?ChallengeSubmission => $this->claim());
        if ($submission === null) {
            return;
        }
        $context = $this->context($submission);
        Log::info('challenge.evaluation_started', $context);
        $started = microtime(true);

        $definition = ChallengeDefinition::query()->find($submission->challenge_definition_id);
        if ($definition === null
            || $definition->definition_fingerprint !== $submission->definition_fingerprint
            || $definition->test_suite_fingerprint !== $submission->test_suite_fingerprint
            || $definition->data()->fingerprint() !== $submission->definition_fingerprint) {
            $this->error($db, SubmissionFailure::EvaluationInvalid, 'definition_changed', $context);

            return;
        }
        $data = $definition->data();

        try {
            $observed = $evaluator->evaluate(EvaluationRequest::for($submission->id, $data, $submission->source));
        } catch (EvaluatorException $e) {
            $this->handleEvaluatorFailure($db, $e, $submission->job_attempts, $context + ['duration_ms' => $this->elapsed($started)]);

            return;
        } catch (Throwable $e) {
            $this->error($db, SubmissionFailure::EvaluationFailed, 'evaluator_error', $context + ['exception' => $e::class]);

            return;
        }
        if (! in_array($observed['status'] ?? null, ChallengeGrader::GRADED_STATUSES, true)) {
            $this->error($db, SubmissionFailure::EvaluationInvalid, 'status', $context);

            return;
        }

        $evaluation = $grader->grade($data, $observed);
        $this->complete($db, $evaluation, $grader->fingerprint($evaluation), $observed, $context + ['duration_ms' => $this->elapsed($started)]);
    }

    /**
     * Laravel's last word (worker timeout, attempts exceeded): the
     * submission must not stay QUEUED or RUNNING.
     */
    public function failed(?Throwable $e): void
    {
        $timeout = $e instanceof TimeoutExceededException || $e instanceof MaxAttemptsExceededException;
        $this->error(
            app(ConnectionInterface::class),
            $timeout ? SubmissionFailure::EvaluationTimeout : SubmissionFailure::EvaluationFailed,
            $timeout ? 'job_timeout' : 'job_failed',
            ['submission_id' => $this->submissionId, 'exception' => $e === null ? null : $e::class],
        );
    }

    private function claim(): ?ChallengeSubmission
    {
        $submission = ChallengeSubmission::query()->whereKey($this->submissionId)->lockForUpdate()->first();
        if ($submission === null || $submission->status->isTerminal()) {
            return null;
        }
        if ($submission->status === SubmissionStatus::Running && $submission->claim_token !== $this->claimToken
            && $submission->lease_expires_at?->isFuture()) {
            Log::info('challenge.duplicate_job_skipped', $this->context($submission));

            return null;
        }
        if ($submission->job_attempts >= (int) config('codedna.challenges.max_job_attempts')) {
            $this->finishError($submission, SubmissionFailure::EvaluationFailed, 'attempts_exhausted');
            Log::warning('challenge.evaluation_failed', $this->context($submission) + ['error_code' => SubmissionFailure::EvaluationFailed->value]);

            return null;
        }

        $now = Carbon::now();
        $submission->forceFill([
            'status' => SubmissionStatus::Running,
            'job_attempts' => $submission->job_attempts + 1,
            'claim_token' => $this->claimToken,
            'lease_expires_at' => $now->copy()->addSeconds($this->timeout + 30),
            'started_at' => $submission->started_at ?? $now,
        ])->save();

        return $submission;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleEvaluatorFailure(ConnectionInterface $db, EvaluatorException $e, int $attempt, array $context): void
    {
        $context += ['error_code' => $e->failure->value, 'reason' => $e->detail];
        if (! $e->retryable || $attempt >= (int) config('codedna.challenges.max_job_attempts')) {
            $this->error($db, $e->failure, $e->detail, $context + ['retryable' => $e->retryable]);

            return;
        }
        $backoff = array_values((array) config('codedna.challenges.backoff_seconds'));
        $delay = (int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 30);

        $released = $db->transaction(function () use ($delay): bool {
            $submission = ChallengeSubmission::query()->whereKey($this->submissionId)->lockForUpdate()->first();
            if ($submission === null || $submission->status !== SubmissionStatus::Running || $submission->claim_token !== $this->claimToken) {
                return false;
            }
            // Keep the lease while waiting, so no other job takes over.
            $submission->forceFill(['lease_expires_at' => Carbon::now()->addSeconds($delay + $this->timeout + 30)])->save();

            return true;
        });
        if ($released) {
            Log::warning('challenge.evaluation_retrying', $context + ['retry_in_seconds' => $delay]);
            $this->release($delay);
        }
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @param  array<string, mixed>  $observed
     * @param  array<string, mixed>  $context
     */
    private function complete(ConnectionInterface $db, array $evaluation, string $fingerprint, array $observed, array $context): void
    {
        $verdict = (string) $evaluation['verdict'];
        $stored = $db->transaction(function () use ($evaluation, $fingerprint, $observed, $verdict): ?ChallengeStatus {
            $submission = ChallengeSubmission::query()->whereKey($this->submissionId)->lockForUpdate()->first();
            if ($submission === null || $submission->status !== SubmissionStatus::Running || $submission->claim_token !== $this->claimToken) {
                return null;
            }
            $challenge = ChallengeInstance::query()->whereKey($submission->challenge_instance_id)->lockForUpdate()->firstOrFail();
            $submission->forceFill([
                'status' => $verdict === ChallengeGrader::PASSED ? SubmissionStatus::Passed : SubmissionStatus::Failed,
                'evaluation' => $evaluation,
                'evaluation_fingerprint' => $fingerprint,
                'execution_status' => $observed['status'],
                'evaluator_version' => $observed['evaluator_version'],
                'runtime' => $observed['runtime'],
                'duration_ms' => min((int) $observed['duration_ms'], 600000),
                'claim_token' => null,
                'lease_expires_at' => null,
                'completed_at' => Carbon::now(),
            ])->save();

            $used = $challenge->attempts_used + 1;
            $status = match (true) {
                $verdict === ChallengeGrader::PASSED => ChallengeStatus::Passed,
                $used >= $challenge->max_attempts => ChallengeStatus::Failed,
                default => ChallengeStatus::Assigned,
            };
            $challenge->forceFill([
                'status' => $status,
                'attempts_used' => $used,
                'last_result' => $verdict,
                'closed_at' => $status->isTerminal() ? Carbon::now() : null,
            ])->save();

            return $status;
        });

        Log::info($stored === null ? 'challenge.result_ignored' : 'challenge.evaluated', $context + [
            'status' => $stored === null ? 'unchanged' : $verdict,
            'challenge_status' => $stored?->value,
            'execution_status' => $observed['status'],
            'evaluation_version' => ChallengeGrader::VERSION,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function error(ConnectionInterface $db, SubmissionFailure $failure, string $detail, array $context): void
    {
        $failed = $db->transaction(function () use ($failure, $detail): bool {
            $submission = ChallengeSubmission::query()->whereKey($this->submissionId)->lockForUpdate()->first();
            if ($submission === null || $submission->status->isTerminal()) {
                return false;
            }
            $this->finishError($submission, $failure, $detail);

            return true;
        });
        if ($failed) {
            Log::warning('challenge.evaluation_failed', $context + ['status' => SubmissionStatus::Error->value, 'error_code' => $failure->value, 'reason' => $detail]);
        }
    }

    /**
     * Ends an attempt that could not be evaluated: it consumes no graded
     * attempt and the challenge accepts the next one.
     */
    private function finishError(ChallengeSubmission $submission, SubmissionFailure $failure, string $detail): void
    {
        $submission->forceFill([
            'status' => SubmissionStatus::Error,
            'failure_code' => $failure->value,
            'failure_detail' => $detail,
            'claim_token' => null,
            'lease_expires_at' => null,
            'completed_at' => Carbon::now(),
        ])->save();
        $challenge = ChallengeInstance::query()->whereKey($submission->challenge_instance_id)->lockForUpdate()->firstOrFail();
        if ($challenge->status === ChallengeStatus::Evaluating) {
            $challenge->forceFill(['status' => ChallengeStatus::Assigned, 'last_result' => 'ERROR'])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ChallengeSubmission $submission): array
    {
        return [
            'submission_id' => $submission->id,
            'challenge_id' => $submission->challenge_instance_id,
            'project_id' => $submission->project_id,
            'attempt' => $submission->attempt_number,
            'job_attempt' => $submission->job_attempts,
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
