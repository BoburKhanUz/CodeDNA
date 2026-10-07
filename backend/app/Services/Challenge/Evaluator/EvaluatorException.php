<?php

declare(strict_types=1);

namespace App\Services\Challenge\Evaluator;

use App\Enums\Challenge\SubmissionFailure;
use RuntimeException;

/**
 * The evaluation could not be completed. Retryable failures are retried by
 * the job; a retry never executes a submission a second time (the spool
 * protocol is keyed by submission id). The message is the failure's safe
 * message.
 */
final class EvaluatorException extends RuntimeException
{
    public function __construct(public readonly SubmissionFailure $failure, public readonly bool $retryable, public readonly string $detail)
    {
        parent::__construct($failure->message());
    }
}
