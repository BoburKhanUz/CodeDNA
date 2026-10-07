<?php

declare(strict_types=1);

namespace App\Enums\Challenge;

/**
 * How demanding an exercise is. It describes the exercise only, never a
 * developer: there is no seniority or level of a person here.
 */
enum ChallengeDifficulty: string
{
    case Beginner = 'BEGINNER';
    case Intermediate = 'INTERMEDIATE';
    case Advanced = 'ADVANCED';
}
