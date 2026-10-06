<?php

declare(strict_types=1);

namespace App\Enums\Dna;

/**
 * Why an analysis run cannot be scored. Messages are safe to show: they
 * never contain result content, hashes or internal details.
 */
enum DnaScoringFailure: string
{
    case RunNotFound = 'RUN_NOT_FOUND';
    case RunNotSucceeded = 'RUN_NOT_SUCCEEDED';
    case ResultMissing = 'RESULT_MISSING';
    case ResultTypeNotScoreable = 'RESULT_TYPE_NOT_SCOREABLE';
    case MetricsVersionUnsupported = 'METRICS_VERSION_UNSUPPORTED';
    case ResultIntegrityFailed = 'RESULT_INTEGRITY_FAILED';
    case ResultInvalid = 'RESULT_INVALID';

    public function message(): string
    {
        return match ($this) {
            self::RunNotFound => 'The analysis run does not exist.',
            self::RunNotSucceeded => 'Only a successfully completed analysis run can be scored.',
            self::ResultMissing => 'The analysis run has no verified result.',
            self::ResultTypeNotScoreable => 'Only static analysis results can be scored.',
            self::MetricsVersionUnsupported => 'The metrics version of this result is not supported by the scoring version.',
            self::ResultIntegrityFailed => 'The stored analysis result does not match its verified hash.',
            self::ResultInvalid => 'The analysis result contains inconsistent metrics.',
        };
    }
}
