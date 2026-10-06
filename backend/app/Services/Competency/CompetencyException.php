<?php

declare(strict_types=1);

namespace App\Services\Competency;

use App\Enums\Competency\CompetencyFailure;
use RuntimeException;

/**
 * A DNA snapshot that cannot be turned into a competency matrix. The message
 * is the failure's safe message.
 */
final class CompetencyException extends RuntimeException
{
    public function __construct(public readonly CompetencyFailure $failure)
    {
        parent::__construct($failure->message());
    }
}
