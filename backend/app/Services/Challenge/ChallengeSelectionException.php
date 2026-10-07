<?php

declare(strict_types=1);

namespace App\Services\Challenge;

use RuntimeException;

/**
 * No challenge can be selected: the snapshot has no eligible gap (or not
 * the requested one), or every eligible definition was already assigned.
 */
final class ChallengeSelectionException extends RuntimeException
{
    public const NO_ELIGIBLE_GAP = 'NO_ELIGIBLE_GAP';

    public const NONE_AVAILABLE = 'NONE_AVAILABLE';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
