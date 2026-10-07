<?php

declare(strict_types=1);

namespace App\Actions\Challenge;

use App\Models\ChallengeInstance;

/**
 * The challenge an assignment request resolved to: newly assigned, or the
 * existing active one that was returned instead.
 */
final readonly class AssignedChallenge
{
    public function __construct(
        public ChallengeInstance $instance,
        public bool $created,
    ) {}
}
