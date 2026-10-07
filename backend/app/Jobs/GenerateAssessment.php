<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Assessment\AssessmentFailure;
use App\Enums\Assessment\AssessmentStatus;
use App\Models\AiAssessment;
use App\Models\SkillGapSnapshot;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Assessment\AssessmentException;
use App\Services\Assessment\AssessmentInputBuilder;
use App\Services\Assessment\AssessmentPrompt;
use App\Services\Assessment\AssessmentResponseValidator;
use App\Services\Assessment\AssessmentSpecification;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Assessment\Provider\AiProviderException;
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
 * Generates one AI assessment (docs/architecture/ai-assessment-v1.md#lifecycle).
 * The payload is the assessment ID only.
 *
 * Each execution:
 *
 * 1. claims the assessment under its row lock (QUEUED -> RUNNING, or resumes
 *    its own lease, or takes over an expired one); a terminal assessment or
 *    one leased by another live job is left alone, so duplicate jobs never
 *    make a second provider call;
 * 2. rebuilds the input from the stored snapshots and checks that its
 *    fingerprint, the prompt fingerprint, the provider and the model still
 *    match the assessment: anything else fails it, never silently
 *    reinterprets;
 * 3. calls the provider once;
 * 4. validates the response (AssessmentResponseValidator) and stores it as
 *    SUCCEEDED, or marks the assessment FAILED with a safe code.
 *
 * Only timeouts, 429 and 5xx are retried, with backoff, within
 * codedna.ai.max_attempts (counted on the row). Invalid output is never
 * retried. Nothing here touches the run, DNA, competency or skill gap
 * snapshots: an AI failure never invalidates deterministic results.
 *
 * Logs carry identifiers, status, duration, attempt and error codes only:
 * never the prompt, input, response, key or headers.
 */
final class GenerateAssessment implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public readonly string $claimToken;

    public function __construct(public readonly string $assessmentId)
    {
        $this->claimToken = (string) Str::uuid();
        $this->onConnection((string) config('codedna.ai.queue_connection'));
        $this->onQueue((string) config('codedna.ai.queue'));
        $this->tries = (int) config('codedna.ai.max_attempts');
        $this->timeout = (int) config('codedna.ai.job_timeout_seconds');
    }

    public function handle(
        ConnectionInterface $db,
        AiProvider $provider,
        AssessmentInputBuilder $builder,
        AssessmentResponseValidator $validator,
    ): void {
        $assessment = $db->transaction(fn (): ?AiAssessment => $this->claim());
        if ($assessment === null) {
            return;
        }
        $context = $this->context($assessment);
        Log::info('assessment.started', $context);
        $started = microtime(true);

        // AI was turned off after the request: nothing more is sent to the provider,
        // including for queued jobs and retries.
        if (! (bool) config('codedna.ai.enabled')) {
            $this->fail($db, AssessmentFailure::AssessmentFailed, 'ai_disabled', $context);

            return;
        }

        try {
            $spec = AssessmentSpecification::forVersion($assessment->assessment_version);
            $gaps = SkillGapSnapshot::query()->findOrFail($assessment->skill_gap_snapshot_id);
            $input = $builder->build($gaps, $spec);
        } catch (AssessmentException $e) {
            $this->fail($db, $e->failure, $e->detail, $context + ['duration_ms' => $this->elapsed($started)]);

            return;
        } catch (Throwable $e) {
            $this->fail($db, AssessmentFailure::EvidenceInvalid, 'evidence_unreadable', $context + ['exception' => $e::class]);

            return;
        }
        if ($input->fingerprint() !== $assessment->input_fingerprint) {
            $this->fail($db, AssessmentFailure::EvidenceChanged, 'input_fingerprint', $context);

            return;
        }
        if ($spec->promptFingerprint() !== $assessment->prompt_fingerprint
            || $provider->name() !== $assessment->provider || $provider->model() !== $assessment->model) {
            // The configuration changed after the request: this identity can no longer be produced.
            $this->fail($db, AssessmentFailure::AssessmentFailed, 'configuration_changed', $context);

            return;
        }
        if (strlen($input->canonicalJson()) > (int) config('codedna.ai.max_input_bytes')) {
            $this->fail($db, AssessmentFailure::InputTooLarge, 'input_size', $context);

            return;
        }

        try {
            $response = $provider->generateAssessment($input, AssessmentPrompt::for($spec, $input));
        } catch (AiProviderException $e) {
            $this->handleProviderFailure($db, $e, $assessment->attempts, $context + ['duration_ms' => $this->elapsed($started)]);

            return;
        } catch (Throwable $e) {
            $this->fail($db, AssessmentFailure::AssessmentFailed, 'provider_error', $context + ['duration_ms' => $this->elapsed($started), 'exception' => $e::class]);

            return;
        }

        try {
            $output = $validator->validate($response->content, $input, $spec, (int) config('codedna.ai.max_output_bytes'));
        } catch (AssessmentException $e) {
            $this->fail($db, $e->failure, $e->detail, $context + ['duration_ms' => $this->elapsed($started)]);

            return;
        }

        $this->succeed($db, $output, $response->metadata(), $context + ['duration_ms' => $this->elapsed($started)]);
    }

    /**
     * Called by Laravel when the job dies (worker timeout, attempts
     * exceeded): the assessment must not stay RUNNING.
     */
    public function failed(?Throwable $e): void
    {
        $timeout = $e instanceof TimeoutExceededException || $e instanceof MaxAttemptsExceededException;
        $this->fail(
            app(ConnectionInterface::class),
            $timeout ? AssessmentFailure::ProviderTimeout : AssessmentFailure::AssessmentFailed,
            $timeout ? 'job_timeout' : 'job_failed',
            ['assessment_id' => $this->assessmentId, 'exception' => $e === null ? null : $e::class],
        );
    }

    private function claim(): ?AiAssessment
    {
        $assessment = AiAssessment::query()->whereKey($this->assessmentId)->lockForUpdate()->first();
        if ($assessment === null || $assessment->status->isTerminal()) {
            return null;
        }

        $now = Carbon::now();
        if ($assessment->status === AssessmentStatus::Running && $assessment->claim_token !== $this->claimToken
            && $assessment->lease_expires_at?->isFuture()) {
            Log::info('assessment.duplicate_job_skipped', $this->context($assessment));

            return null;
        }
        if ($assessment->attempts >= (int) config('codedna.ai.max_attempts')) {
            // Attempts already used up (e.g. redeliveries after crashes).
            $this->markFailed($assessment, AssessmentFailure::ProviderUnavailable, 'attempts_exhausted');
            Log::warning('assessment.failed', $this->context($assessment) + ['error_code' => AssessmentFailure::ProviderUnavailable->value]);

            return null;
        }

        $assessment->forceFill([
            'status' => AssessmentStatus::Running,
            'attempts' => $assessment->attempts + 1,
            'claim_token' => $this->claimToken,
            'lease_expires_at' => $now->copy()->addSeconds($this->timeout + 30),
            'started_at' => $assessment->started_at ?? $now,
        ])->save();

        return $assessment;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleProviderFailure(ConnectionInterface $db, AiProviderException $e, int $attempt, array $context): void
    {
        $context += ['error_code' => $e->failure->value, 'http_status' => $e->status, 'reason' => $e->detail];
        if (! $e->retryable || $attempt >= (int) config('codedna.ai.max_attempts')) {
            $this->fail($db, $e->failure, $e->detail, $context + ['retryable' => $e->retryable]);

            return;
        }

        $backoff = array_values((array) config('codedna.ai.backoff_seconds'));
        $base = (int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 30);
        // A Retry-After is honoured, up to the longest configured backoff.
        $delay = max($base, min((int) $e->retryAfterSeconds, (int) max($backoff ?: [60])));

        $released = $db->transaction(function () use ($delay): bool {
            $assessment = AiAssessment::query()->whereKey($this->assessmentId)->lockForUpdate()->first();
            if ($assessment === null || $assessment->status !== AssessmentStatus::Running || $assessment->claim_token !== $this->claimToken) {
                return false;
            }
            // Keep the lease while waiting, so no other job takes over.
            $assessment->forceFill(['lease_expires_at' => Carbon::now()->addSeconds($delay + $this->timeout + 30)])->save();

            return true;
        });

        if ($released) {
            Log::warning('assessment.retrying', $context + ['retry_in_seconds' => $delay]);
            $this->release($delay);
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, int|string|null>  $metadata
     * @param  array<string, mixed>  $context
     */
    private function succeed(ConnectionInterface $db, array $output, array $metadata, array $context): void
    {
        $stored = $db->transaction(function () use ($output, $metadata): bool {
            $assessment = AiAssessment::query()->whereKey($this->assessmentId)->lockForUpdate()->first();
            if ($assessment === null || $assessment->status !== AssessmentStatus::Running || $assessment->claim_token !== $this->claimToken) {
                return false;
            }
            $assessment->forceFill([
                'status' => AssessmentStatus::Succeeded,
                'output' => $output,
                'output_fingerprint' => CanonicalJson::hash(json_decode((string) json_encode($output, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)),
                'provider_metadata' => array_filter($metadata, fn ($v): bool => $v !== null) ?: null,
                'claim_token' => null,
                'lease_expires_at' => null,
                'completed_at' => Carbon::now(),
            ])->save();

            return true;
        });

        Log::info($stored ? 'assessment.succeeded' : 'assessment.result_ignored', $context + [
            'status' => $stored ? AssessmentStatus::Succeeded->value : 'unchanged',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(ConnectionInterface $db, AssessmentFailure $failure, ?string $detail, array $context): void
    {
        $failed = $db->transaction(function () use ($failure, $detail): bool {
            $assessment = AiAssessment::query()->whereKey($this->assessmentId)->lockForUpdate()->first();
            if ($assessment === null || $assessment->status->isTerminal()) {
                return false;
            }
            $this->markFailed($assessment, $failure, $detail);

            return true;
        });

        if ($failed) {
            Log::warning('assessment.failed', $context + ['status' => AssessmentStatus::Failed->value, 'error_code' => $failure->value, 'reason' => $detail]);
        }
    }

    private function markFailed(AiAssessment $assessment, AssessmentFailure $failure, ?string $detail): void
    {
        $assessment->forceFill([
            'status' => AssessmentStatus::Failed,
            'failure_code' => $failure->value,
            'failure_detail' => $detail,
            'claim_token' => null,
            'lease_expires_at' => null,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(AiAssessment $assessment): array
    {
        return [
            'assessment_id' => $assessment->id,
            'project_id' => $assessment->project_id,
            'input_fingerprint' => $assessment->input_fingerprint,
            'provider' => $assessment->provider,
            'model' => $assessment->model,
            'attempt' => $assessment->attempts,
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
