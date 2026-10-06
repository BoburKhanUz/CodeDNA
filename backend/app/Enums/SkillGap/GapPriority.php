<?php

declare(strict_types=1);

namespace App\Enums\SkillGap;

/**
 * Priority of a material gap, from its size and evidence quality only. It
 * ranks measurable differences; it says nothing about a person.
 */
enum GapPriority: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
}
