<?php

declare(strict_types=1);

namespace App\Enums\Dna;

/**
 * SCORED: the dimension has a score and takes part in the overall score.
 * UNAVAILABLE: its required evidence is missing, unsupported or too small;
 * it has no score and its weight is redistributed (never counted as 0).
 */
enum DimensionStatus: string
{
    case Scored = 'SCORED';
    case Unavailable = 'UNAVAILABLE';
}
