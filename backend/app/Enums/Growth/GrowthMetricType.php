<?php

declare(strict_types=1);

namespace App\Enums\Growth;

/**
 * Which stored result a growth observation compares. Scores (DNA,
 * competency) are better when higher; skill gaps are better when lower.
 */
enum GrowthMetricType: string
{
    case Dna = 'DNA';
    case Competency = 'COMPETENCY';
    case SkillGap = 'SKILL_GAP';

    public function higherIsBetter(): bool
    {
        return $this !== self::SkillGap;
    }
}
