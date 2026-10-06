<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\Assessment\AssessmentFailure;
use RuntimeException;

/**
 * An assessment step that failed. The message is the failure's safe message;
 * $detail is a short, fixed rule identifier (never provider text).
 */
final class AssessmentException extends RuntimeException
{
    public function __construct(public readonly AssessmentFailure $failure, public readonly ?string $detail = null)
    {
        parent::__construct($failure->message());
    }
}
