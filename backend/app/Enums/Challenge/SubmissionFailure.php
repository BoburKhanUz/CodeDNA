<?php

declare(strict_types=1);

namespace App\Enums\Challenge;

/**
 * Why an evaluation could not be completed (submission status ERROR).
 * Messages are fixed and safe: never evaluator output, paths or internals.
 */
enum SubmissionFailure: string
{
    case EvaluatorUnavailable = 'EVALUATOR_UNAVAILABLE';
    case EvaluationTimeout = 'EVALUATION_TIMEOUT';
    case EvaluationInterrupted = 'EVALUATION_INTERRUPTED';
    case EvaluationRejected = 'EVALUATION_REJECTED';
    case EvaluationInvalid = 'EVALUATION_INVALID';
    case EvaluationStale = 'EVALUATION_STALE';
    case EvaluationFailed = 'EVALUATION_FAILED';

    public function message(): string
    {
        return match ($this) {
            self::EvaluatorUnavailable => 'The challenge evaluator is unavailable. This attempt was not counted.',
            self::EvaluationTimeout => 'The evaluation did not complete in time. This attempt was not counted.',
            self::EvaluationInterrupted => 'The evaluation was interrupted. This attempt was not counted.',
            self::EvaluationRejected => 'The evaluator rejected the request. This attempt was not counted.',
            self::EvaluationInvalid => 'The evaluator returned an invalid result. This attempt was not counted.',
            self::EvaluationStale => 'The evaluation did not complete. This attempt was not counted.',
            self::EvaluationFailed => 'The evaluation could not be completed. This attempt was not counted.',
        };
    }
}
