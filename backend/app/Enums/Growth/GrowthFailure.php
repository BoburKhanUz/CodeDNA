<?php

declare(strict_types=1);

namespace App\Enums\Growth;

/**
 * Why a growth snapshot could not be calculated. Fixed codes, safe to log.
 */
enum GrowthFailure: string
{
    case SkillGapSnapshotNotFound = 'SKILL_GAP_SNAPSHOT_NOT_FOUND';
    case AssessmentIncomplete = 'ASSESSMENT_INCOMPLETE';
}
