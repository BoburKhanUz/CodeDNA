<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Assessment\Provider\AiProviderResponse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The one way the application reaches a model (Phase 29,
 * docs/architecture/ai-intelligence-v1.md#ai-gateway). Around the configured
 * ModelClient it adds:
 *
 * - a context budget: estimated prompt tokens plus the output bound must fit
 *   the configured context window, or the request is refused
 *   (INPUT_TOO_LARGE, context_budget) before anything is sent;
 * - a concurrency limit across every worker (a Redis semaphore of
 *   AI_MAX_CONCURRENCY slots); a job that waits AI_SLOT_WAIT_SECONDS without
 *   a slot gets a retryable PROVIDER_UNAVAILABLE (slots_busy);
 * - metrics (AiMetrics) and one log line per call with the task, duration,
 *   outcome and error code. Never the prompt, the evidence or the answer.
 *
 * It makes exactly one attempt: retries belong to the job, which counts
 * them. There is no fallback to any other provider.
 */
final readonly class AiGateway
{
    public function __construct(private ModelClient $client, private AiMetrics $metrics, private Repository $config) {}

    public function provider(): string
    {
        return $this->client->name();
    }

    public function model(): string
    {
        return $this->client->model();
    }

    /**
     * Whether $request fits the context window (estimate).
     */
    public function fits(AiRequest $request): bool
    {
        return $request->estimatedPromptTokens() + $request->maxOutputTokens <= (int) $this->config->get('codedna.ai.context_tokens');
    }

    /**
     * @throws AiProviderException
     */
    public function complete(AiRequest $request): AiProviderResponse
    {
        if (! $this->fits($request)) {
            $this->record($request, 0, AssessmentFailure::InputTooLarge->value);
            throw AiProviderException::permanent(AssessmentFailure::InputTooLarge, 'context_budget');
        }

        $result = null;
        $error = null;
        $acquired = false;
        Redis::funnel('codedna:ai-slots')
            ->limit(max(1, (int) $this->config->get('codedna.ai.max_concurrency')))
            ->releaseAfter((int) $this->config->get('codedna.ai.timeout_seconds') + 30)
            ->block(max(1, (int) $this->config->get('codedna.ai.slot_wait_seconds')))
            ->then(function () use ($request, &$result, &$error, &$acquired): void {
                $acquired = true;
                $this->metrics->started();
                $started = microtime(true);
                try {
                    $result = $this->client->complete($request);
                    $this->record($request, self::elapsed($started), null);
                } catch (Throwable $e) {
                    $error = $e;
                    $this->record($request, self::elapsed($started), $e instanceof AiProviderException ? $e->failure->value : 'ERROR', $e instanceof AiProviderException ? $e->detail : null);
                } finally {
                    $this->metrics->finished();
                }
            }, static function (): void {});

        if (! $acquired) {
            $this->metrics->record('SLOTS_BUSY', 0);
            Log::info('ai.slots_busy', ['task' => $request->task, 'provider' => $this->client->name()]);
            throw AiProviderException::retryable(AssessmentFailure::ProviderUnavailable, 'slots_busy');
        }
        if ($error !== null) {
            throw $error;
        }

        /** @var AiProviderResponse $result */
        return $result;
    }

    private function record(AiRequest $request, int $durationMs, ?string $errorCode, ?string $reason = null): void
    {
        $this->metrics->record($errorCode ?? 'SUCCEEDED', $durationMs);
        Log::log($errorCode === null ? 'info' : 'warning', 'ai.completion', [
            'task' => $request->task,
            'provider' => $this->client->name(),
            'model' => $this->client->model(),
            'duration_ms' => $durationMs,
            'status' => $errorCode === null ? 'SUCCEEDED' : 'FAILED',
            'error_code' => $errorCode,
            'reason' => $reason,
        ]);
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
