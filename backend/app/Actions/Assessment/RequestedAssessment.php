<?php

declare(strict_types=1);

namespace App\Actions\Assessment;

use App\Models\AiAssessment;

/**
 * The assessment a request resolved to: newly created (QUEUED), or the
 * existing one with the same identity that was returned instead.
 */
final readonly class RequestedAssessment
{
    public function __construct(
        public AiAssessment $assessment,
        public bool $created,
    ) {}
}
