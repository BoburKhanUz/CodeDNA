<?php

declare(strict_types=1);

namespace App\Services\Challenge\Evaluator;

use App\Enums\Challenge\SubmissionFailure;
use App\Services\Analyzer\JsonSchemaValidator;
use JsonException;

/**
 * Talks to the evaluator service through the private spool volume
 * (docs/architecture/challenge-evaluator.md#spool-protocol). The evaluator
 * has no network; this is its only channel.
 *
 *     requests/<id>.json  written here (atomically, by rename)
 *     work/<id>.json      claimed by the evaluator (renamed there)
 *     results/<id>.json   written by the evaluator, read and removed here
 *
 * Execution is at most once per submission: a retry first looks for the
 * result and for a claimed request, and only submits when neither exists.
 * A request still waiting at the deadline is withdrawn (it never ran); a
 * claimed one is left to finish and is picked up by the next attempt.
 */
final class SpoolChallengeEvaluator implements ChallengeEvaluator
{
    private const MAX_RESULT_BYTES = 262144;

    /** Isolation levels, weakest first (evaluator/evaluator/isolation.py). */
    public const ISOLATION_LEVELS = ['container', 'gvisor'];

    public function __construct(
        private readonly string $spool,
        private readonly int $waitSeconds,
        private readonly int $heartbeatMaxAgeSeconds = 30,
        private readonly int $pollMilliseconds = 200,
        private readonly string $requiredIsolation = 'container',
    ) {}

    public function name(): string
    {
        return 'spool';
    }

    public function available(): bool
    {
        $beat = @file_get_contents($this->spool.'/heartbeat');
        if ($beat === false) {
            return false;
        }
        $decoded = json_decode($beat, true);

        if (! is_array($decoded) || ! is_int($decoded['at'] ?? null) || time() - $decoded['at'] > $this->heartbeatMaxAgeSeconds) {
            return false;
        }

        // Phase 25: the evaluator attests its runtime isolation; code is only
        // submitted to one at least as isolated as required (gVisor in
        // production). A heartbeat without the field predates the contract
        // and counts as container isolation. Fail closed on anything else.
        $attested = array_search($decoded['isolation'] ?? 'container', self::ISOLATION_LEVELS, true);
        $required = array_search($this->requiredIsolation, self::ISOLATION_LEVELS, true);

        return $attested !== false && $required !== false && $attested >= $required;
    }

    public function evaluate(EvaluationRequest $request): array
    {
        $id = $request->submissionId;
        if (preg_match('/^[0-9a-z]{26}$/', $id) !== 1) {
            throw new EvaluatorException(SubmissionFailure::EvaluationRejected, false, 'invalid_id');
        }
        $result = "{$this->spool}/results/{$id}.json";
        $claimed = "{$this->spool}/work/{$id}.json";
        $queued = "{$this->spool}/requests/{$id}.json";

        if (! is_file($result) && ! is_file($claimed) && ! is_file($queued)) {
            // Where stronger isolation is required (production), the job checks
            // the attestation again right before submitting: the queue may run
            // long after the request was accepted. Retryable, never executed.
            if ($this->requiredIsolation !== self::ISOLATION_LEVELS[0] && ! $this->available()) {
                throw new EvaluatorException(SubmissionFailure::EvaluatorUnavailable, true, 'isolation_unverified');
            }
            $this->submit($request, $queued);
        }

        $deadline = microtime(true) + $this->waitSeconds;
        while (! is_file($result)) {
            if (microtime(true) >= $deadline) {
                // Withdraw a request nobody claimed: it never ran.
                if (@unlink($queued)) {
                    throw new EvaluatorException(SubmissionFailure::EvaluatorUnavailable, true, 'not_claimed');
                }
                if (is_file($result)) {
                    break;
                }
                throw new EvaluatorException(SubmissionFailure::EvaluationTimeout, true, 'still_running');
            }
            usleep($this->pollMilliseconds * 1000);
        }

        return $this->read($result, $id);
    }

    private function submit(EvaluationRequest $request, string $queued): void
    {
        $temporary = dirname($queued).'/.tmp-'.$request->submissionId;
        $body = json_encode($request->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($temporary, $body) === false || ! @rename($temporary, $queued)) {
            @unlink($temporary);
            throw new EvaluatorException(SubmissionFailure::EvaluatorUnavailable, true, 'spool_unwritable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path, string $id): array
    {
        $raw = @file_get_contents($path, false, null, 0, self::MAX_RESULT_BYTES + 1);
        @unlink($path);
        if ($raw === false || strlen($raw) > self::MAX_RESULT_BYTES) {
            throw new EvaluatorException(SubmissionFailure::EvaluationInvalid, false, 'unreadable');
        }
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EvaluatorException(SubmissionFailure::EvaluationInvalid, false, 'not_json');
        }
        $schema = json_decode((string) file_get_contents(resource_path('challenges/evaluator-result-1.schema.json')), true, 64, JSON_THROW_ON_ERROR);
        if ((new JsonSchemaValidator)->validate($decoded, $schema) !== [] || $decoded->id !== $id) {
            throw new EvaluatorException(SubmissionFailure::EvaluationInvalid, false, 'schema');
        }
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return match ($result['status']) {
            'INTERRUPTED' => throw new EvaluatorException(SubmissionFailure::EvaluationInterrupted, false, 'interrupted'),
            'REJECTED' => throw new EvaluatorException(SubmissionFailure::EvaluationRejected, false, 'rejected'),
            default => $result,
        };
    }
}
