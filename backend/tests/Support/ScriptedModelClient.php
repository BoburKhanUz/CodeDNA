<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Ai\AiRequest;
use App\Services\Ai\FakeModelClient;
use App\Services\Ai\ModelClient;
use App\Services\Ai\ModelHealth;
use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Assessment\Provider\AiProviderResponse;
use Closure;

/**
 * A model client for tests (Phase 29) that plays one scripted mode per call:
 *
 * - "valid": the deterministic template (FakeModelClient), which passes;
 * - "timeout", "unavailable", "rate_limited", "auth", "rejected",
 *   "truncated": the normalized provider failures;
 * - a Closure(array $evidenceById, AiRequest): array|string producing the
 *   answer (an array is JSON-encoded), for injection and invalid outputs.
 *
 * Every request is recorded so tests can inspect exactly what a model
 * would have received.
 */
final class ScriptedModelClient implements ModelClient
{
    /** @var list<AiRequest> */
    public array $requests = [];

    /** @var list<string|Closure> */
    private array $modes;

    public bool $healthy = true;

    /** How many times health() was called. */
    public int $healthChecks = 0;

    public ?bool $modelAvailable = true;

    public function __construct(string|Closure ...$modes)
    {
        $this->modes = $modes === [] ? ['valid'] : array_values($modes);
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return 'scripted-model:1b';
    }

    public function health(): ModelHealth
    {
        $this->healthChecks++;

        return new ModelHealth($this->healthy, $this->healthy ? $this->modelAvailable : null, '0.0.0-test', $this->healthy ? null : 'transport_error');
    }

    public function complete(AiRequest $request): AiProviderResponse
    {
        $this->requests[] = $request;
        $mode = $this->modes[min(count($this->requests) - 1, count($this->modes) - 1)];
        if ($mode instanceof Closure) {
            $answer = $mode(self::evidence($request), $request);

            return new AiProviderResponse(is_string($answer) ? $answer : (string) json_encode($answer), 'scripted-model:1b', null, 100, 50);
        }

        return match ($mode) {
            'valid' => (new FakeModelClient)->complete($request),
            'timeout' => throw AiProviderException::retryable(AssessmentFailure::ProviderTimeout, 'transport_timeout'),
            'unavailable' => throw AiProviderException::retryable(AssessmentFailure::ProviderUnavailable, 'server_error', 503),
            'rate_limited' => throw AiProviderException::retryable(AssessmentFailure::ProviderRateLimited, 'rate_limited', 429, 5),
            'auth' => throw AiProviderException::permanent(AssessmentFailure::ProviderAuthFailed, 'auth_rejected', 401),
            'rejected' => throw AiProviderException::permanent(AssessmentFailure::ProviderRejected, 'model_not_found', 404),
            'truncated' => throw AiProviderException::permanent(AssessmentFailure::OutputTooLarge, 'truncated', 200),
        };
    }

    /**
     * The evidence the model would have received, by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function evidence(AiRequest $request): array
    {
        $begin = strpos($request->user, '<<<BEGIN_UNTRUSTED_EVIDENCE_JSON>>>');
        $end = strpos($request->user, '<<<END_UNTRUSTED_EVIDENCE_JSON>>>');
        if ($begin === false || $end === false) {
            return [];
        }
        $start = $begin + strlen('<<<BEGIN_UNTRUSTED_EVIDENCE_JSON>>>');
        $payload = json_decode(trim(substr($request->user, $start, $end - $start)), true);
        $items = [];
        foreach ((array) ($payload['evidence'] ?? []) as $item) {
            $items[$item['id']] = $item;
        }

        return $items;
    }
}
