<?php

declare(strict_types=1);

namespace App\Services\Dna;

use App\Enums\Dna\DnaScoringFailure;
use RuntimeException;

/**
 * A run that cannot be scored. The message is the failure's safe message.
 */
final class DnaScoringException extends RuntimeException
{
    public function __construct(public readonly DnaScoringFailure $failure)
    {
        parent::__construct($failure->message());
    }
}
