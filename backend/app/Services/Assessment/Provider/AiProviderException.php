<?php

declare(strict_types=1);

namespace App\Services\Assessment\Provider;

use App\Enums\Assessment\AssessmentFailure;
use RuntimeException;

/**
 * A normalized provider failure: a failure code, whether another attempt
 * may succeed, and an optional Retry-After. The message is the code's safe
 * message; the provider's response body is never included.
 */
final class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly AssessmentFailure $failure,
        public readonly bool $retryable,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($failure->message());
    }

    public static function retryable(AssessmentFailure $failure, string $detail, ?int $status = null, ?int $retryAfter = null): self
    {
        return new self($failure, true, $status, $retryAfter, $detail);
    }

    public static function permanent(AssessmentFailure $failure, string $detail, ?int $status = null): self
    {
        return new self($failure, false, $status, null, $detail);
    }
}
