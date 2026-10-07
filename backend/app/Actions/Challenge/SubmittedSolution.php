<?php

declare(strict_types=1);

namespace App\Actions\Challenge;

use App\Models\ChallengeSubmission;

/**
 * The submission a request resolved to: newly queued, or the earlier one
 * with the same Idempotency-Key and source.
 */
final readonly class SubmittedSolution
{
    public function __construct(
        public ChallengeSubmission $submission,
        public bool $created,
    ) {}
}
