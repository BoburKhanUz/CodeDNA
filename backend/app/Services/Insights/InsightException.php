<?php

declare(strict_types=1);

namespace App\Services\Insights;

use App\Enums\Insights\InsightFailure;
use RuntimeException;

/**
 * An insight cannot be built or a response was refused: a safe failure code
 * and a fixed rule identifier, never the offending text.
 */
final class InsightException extends RuntimeException
{
    public function __construct(public readonly InsightFailure $failure, public readonly string $detail)
    {
        parent::__construct($failure->message());
    }
}
