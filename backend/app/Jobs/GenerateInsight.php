<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Assessment\AssessmentStatus;
use App\Enums\Insights\InsightFailure;
use App\Models\AiInsight;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiRequest;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Insights\InsightException;
use App\Services\Insights\InsightInputBuilder;
use App\Services\Insights\InsightResponseValidator;
use App\Services\Insights\InsightSpecification;
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
 * Generates one AI insight (Phase 29,
 * docs/architecture/ai-intelligence-v1.md#lifecycle), with the same
 * guarantees as the Phase 15 assessment job. The payload is the insight ID
 * only.
 *
 * 1. Claim under the row lock (QUEUED -> RUNNING, or its own lease, or an
 *    expired one); duplicates and terminal rows are left alone.
 * 2. Rebuild the input from the stored subject: the fingerprint, prompt,
 *    provider and model must still match, or the insight fails instead of
 *    silently interpreting other evidence or using another model.
 * 3. One call through the AI gateway, outside any database transaction.
 * 4. Validate (InsightResponseValidator) and store SUCCEEDED, or FAILED with
 *    a safe code.
 *
 * Only timeouts, 429, 5xx and busy slots are retried, within
 * codedna.ai.max_attempts (counted on the row). Nothing here writes to a
 * growth, roadmap, challenge, DNA, competency or skill gap row: an AI
 * failure never touches deterministic results.
 */
final class GenerateInsight implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public readonly string $claimToken;

    public function __construct(public readonly string $insightId)
    {
        $this->claimToken = (string) Str::uuid();
        $this->onConnection((string) config('codedna.ai.queue_connection'));
        $this->onQueue((string) config('codedna.ai.queue'));
        $this->tries = (int) config('codedna.ai.max_attempts');
        $this->timeout = (int) config('codedna.ai.job_timeout_seconds');
    }

    public function handle(ConnectionInterface $db, AiGateway $gateway, InsightInputBuilder $builder, InsightResponseValidator $validator): void
    {
        $insight = $db->transaction(fn (): ?AiInsight => $this->claim());
        if ($insight === null) {
            return;
        }
        $context = $this->context($insight);
        Log::info('insight.started', $context);
        $started = microtime(true);

        if (! (bool) config('codedna.ai.enabled')) {
            $this->fail($db, InsightFailure::InsightFailed, 'ai_disabled', $context);

            return;
        }

        try {
            $spec = InsightSpecification::forVersion($insight->insight_version);
            $subject = $builder->subject($insight->kind, $insight->project_id, $insight->subjectId())
                ?? throw new InsightException(InsightFailure::EvidenceInvalid, 'subject_missing');
            $input = $builder->build($insight->kind, $subject, $spec);
        } catch (InsightException $e) {
            $this->fail($db, $e->failure === InsightFailure::EvidenceInvalid ? InsightFailure::EvidenceChanged : $e->failure, $e->detail, $context);

            return;
        } catch (Throwable $e) {
            $this->fail($db, InsightFailure::EvidenceInvalid, 'evidence_unreadable', $context + ['exception' => $e::class]);

            return;
        }
        if ($input->fingerprint() !== $insight->input_fingerprint) {
            $this->fail($db, InsightFailure::EvidenceChanged, 'input_fingerprint', $context);

            return;
        }
        if ($spec->promptFingerprint($insight->kind) !== $insight->prompt_fingerprint
            || $gateway->provider() !== $insight->provider || $gateway->model() !== $insight->model) {
            $this->fail($db, InsightFailure::InsightFailed, 'configuration_changed', $context);

            return;
        }
        if (strlen($input->canonicalJson()) > (int) config('codedna.ai.max_input_bytes')) {
            $this->fail($db, InsightFailure::InputTooLarge, 'input_size', $context);

            return;
        }

        $request = new AiRequest(strtolower($insight->kind->value), $spec->systemPrompt($insight->kind), $spec->userMessage($input),
            'codedna_insight_v1', $spec->providerSchema($input), (int) config('codedna.ai.max_output_tokens'));
        try {
            $response = $gateway->complete($request);
        } catch (AiProviderException $e) {
            $this->handleProviderFailure($db, $e, $insight->attempts, $context + ['duration_ms' => $this->elapsed($started)]);

            return;
        } catch (Throwable $e) {
            $this->fail($db, InsightFailure::InsightFailed, 'provider_error', $context + ['duration_ms' => $this->elapsed($started), 'exception' => $e::class]);

            return;
        }

        try {
            $output = $validator->validate($response->content, $input, $spec, (int) config('codedna.ai.max_output_bytes'));
        } catch (InsightException $e) {
            $this->fail($db, $e->failure, $e->detail, $context + ['duration_ms' => $this->elapsed($started)]);

            return;
        }

        $this->succeed($db, $output, $response->metadata() + ['duration_ms' => $this->elapsed($started)], $context + ['duration_ms' => $this->elapsed($started)]);
    }

    public function failed(?Throwable $e): void
    {
        $timeout = $e instanceof TimeoutExceededException || $e instanceof MaxAttemptsExceededException;
        $this->fail(
            app(ConnectionInterface::class),
            $timeout ? InsightFailure::ProviderTimeout : InsightFailure::InsightFailed,
            $timeout ? 'job_timeout' : 'job_failed',
            ['insight_id' => $this->insightId, 'exception' => $e === null ? null : $e::class],
        );
    }

    private function claim(): ?AiInsight
    {
        $insight = AiInsight::query()->whereKey($this->insightId)->lockForUpdate()->first();
        if ($insight === null || $insight->status->isTerminal()) {
            return null;
        }
        if ($insight->status === AssessmentStatus::Running && $insight->claim_token !== $this->claimToken && $insight->lease_expires_at?->isFuture()) {
            Log::info('insight.duplicate_job_skipped', $this->context($insight));

            return null;
        }
        if ($insight->attempts >= (int) config('codedna.ai.max_attempts')) {
            $this->markFailed($insight, InsightFailure::ProviderUnavailable, 'attempts_exhausted');
            Log::warning('insight.failed', $this->context($insight) + ['error_code' => InsightFailure::ProviderUnavailable->value]);

            return null;
        }

        $now = Carbon::now();
        $insight->forceFill([
            'status' => AssessmentStatus::Running,
            'attempts' => $insight->attempts + 1,
            'claim_token' => $this->claimToken,
            'lease_expires_at' => $now->copy()->addSeconds($this->timeout + 30),
            'started_at' => $insight->started_at ?? $now,
        ])->save();

        return $insight;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleProviderFailure(ConnectionInterface $db, AiProviderException $e, int $attempt, array $context): void
    {
        $failure = InsightFailure::fromAssessment($e->failure);
        $context += ['error_code' => $failure->value, 'http_status' => $e->status, 'reason' => $e->detail];
        if (! $e->retryable || $attempt >= (int) config('codedna.ai.max_attempts')) {
            $this->fail($db, $failure, $e->detail, $context + ['retryable' => $e->retryable]);

            return;
        }

        $backoff = array_values((array) config('codedna.ai.backoff_seconds'));
        $base = (int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 30);
        $delay = max($base, min((int) $e->retryAfterSeconds, (int) max($backoff ?: [60])));

        $released = $db->transaction(function () use ($delay): bool {
            $insight = AiInsight::query()->whereKey($this->insightId)->lockForUpdate()->first();
            if ($insight === null || $insight->status !== AssessmentStatus::Running || $insight->claim_token !== $this->claimToken) {
                return false;
            }
            $insight->forceFill(['lease_expires_at' => Carbon::now()->addSeconds($delay + $this->timeout + 30)])->save();

            return true;
        });

        if ($released) {
            Log::warning('insight.retrying', $context + ['retry_in_seconds' => $delay]);
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
            $insight = AiInsight::query()->whereKey($this->insightId)->lockForUpdate()->first();
            if ($insight === null || $insight->status !== AssessmentStatus::Running || $insight->claim_token !== $this->claimToken) {
                return false;
            }
            $insight->forceFill([
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

        Log::info($stored ? 'insight.succeeded' : 'insight.result_ignored', $context + ['status' => $stored ? AssessmentStatus::Succeeded->value : 'unchanged']);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(ConnectionInterface $db, InsightFailure $failure, ?string $detail, array $context): void
    {
        $failed = $db->transaction(function () use ($failure, $detail): bool {
            $insight = AiInsight::query()->whereKey($this->insightId)->lockForUpdate()->first();
            if ($insight === null || $insight->status->isTerminal()) {
                return false;
            }
            $this->markFailed($insight, $failure, $detail);

            return true;
        });

        if ($failed) {
            Log::warning('insight.failed', $context + ['status' => AssessmentStatus::Failed->value, 'error_code' => $failure->value, 'reason' => $detail]);
        }
    }

    private function markFailed(AiInsight $insight, InsightFailure $failure, ?string $detail): void
    {
        $insight->forceFill([
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
    private function context(AiInsight $insight): array
    {
        return [
            'insight_id' => $insight->id,
            'project_id' => $insight->project_id,
            'kind' => $insight->kind->value,
            'input_fingerprint' => $insight->input_fingerprint,
            'provider' => $insight->provider,
            'model' => $insight->model,
            'attempt' => $insight->attempts,
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
