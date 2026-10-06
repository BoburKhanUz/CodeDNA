<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

use App\Enums\AnalysisFailure;
use RuntimeException;

/**
 * A failed analyzer call, already classified: the failure code stored on the
 * run, whether a later attempt may succeed, and an optional Retry-After.
 * The message is a fixed identifier for logs, never response content.
 */
final class AnalyzerException extends RuntimeException
{
    public function __construct(
        public readonly AnalysisFailure $failure,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?string $analyzerCode = null,
        public readonly ?int $retryAfterSeconds = null,
        string $reason = '',
    ) {
        parent::__construct($reason !== '' ? $reason : $failure->value);
    }

    public static function retryable(AnalysisFailure $failure, string $reason, ?int $httpStatus = null, ?string $analyzerCode = null, ?int $retryAfter = null): self
    {
        return new self($failure, true, $httpStatus, $analyzerCode, $retryAfter, $reason);
    }

    public static function permanent(AnalysisFailure $failure, string $reason, ?int $httpStatus = null, ?string $analyzerCode = null): self
    {
        return new self($failure, false, $httpStatus, $analyzerCode, null, $reason);
    }
}
