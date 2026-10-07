<?php

declare(strict_types=1);

namespace App\Enums\Roadmap;

/**
 * What a learning step asks the developer to do. The roadmap only explains
 * the step; it delivers no content and runs nothing.
 *
 * - READ: understand a concept;
 * - PRACTICE: apply it to the developer's own code;
 * - CHALLENGE: practise on a Phase 16 coding challenge of the competency;
 * - REASSESS: run a new CodeDNA analysis (instruction only: the roadmap
 *   never starts an analysis).
 */
enum RoadmapStepType: string
{
    case Read = 'READ';
    case Practice = 'PRACTICE';
    case Challenge = 'CHALLENGE';
    case Reassess = 'REASSESS';
}
