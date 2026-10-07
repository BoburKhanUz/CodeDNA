<?php

declare(strict_types=1);

namespace App\Services\Challenge\Evaluator;

/**
 * Runs a submission in the isolated evaluator and returns what it observed
 * (docs/architecture/challenge-evaluator.md). Implementations never execute
 * code themselves and never receive expected outputs.
 */
interface ChallengeEvaluator
{
    /** Stable identifier, e.g. "spool". */
    public function name(): string;

    /** Whether submissions can be evaluated now. */
    public function available(): bool;

    /**
     * @return array<string, mixed> a validated codedna-evaluator/1 result
     *
     * @throws EvaluatorException
     */
    public function evaluate(EvaluationRequest $request): array;
}
