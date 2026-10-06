<?php

declare(strict_types=1);

namespace App\Enums\SkillGap;

/**
 * GAPS_IDENTIFIED: at least one material gap. NO_MATERIAL_GAPS: at least one
 * targeted competency was measured and none has a material gap.
 * INSUFFICIENT_DATA: no targeted competency could be measured ("no data" is
 * not "no gaps").
 */
enum SkillGapSnapshotStatus: string
{
    case GapsIdentified = 'GAPS_IDENTIFIED';
    case NoMaterialGaps = 'NO_MATERIAL_GAPS';
    case InsufficientData = 'INSUFFICIENT_DATA';
}
