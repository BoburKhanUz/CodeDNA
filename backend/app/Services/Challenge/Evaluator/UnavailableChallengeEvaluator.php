<?php

declare(strict_types=1);

namespace App\Services\Challenge\Evaluator;

use App\Enums\Challenge\SubmissionFailure;

/**
 * CHALLENGE_EVALUATOR=none: no execution path is configured. Challenges can
 * be assigned and read, but submissions are refused (the API says so); there
 * is never a fallback to running code anywhere else.
 */
final class UnavailableChallengeEvaluator implements ChallengeEvaluator
{
    public function name(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function evaluate(EvaluationRequest $request): array
    {
        throw new EvaluatorException(SubmissionFailure::EvaluatorUnavailable, false, 'not_configured');
    }
}
