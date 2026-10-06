<?php

declare(strict_types=1);

namespace App\Enums\SkillGap;

/**
 * Why a competency snapshot cannot be analyzed. Messages are safe to show.
 */
enum SkillGapFailure: string
{
    case CompetencySnapshotNotFound = 'COMPETENCY_SNAPSHOT_NOT_FOUND';
    case CompetencyVersionUnsupported = 'COMPETENCY_VERSION_UNSUPPORTED';
    case CompetencySnapshotInvalid = 'COMPETENCY_SNAPSHOT_INVALID';

    public function message(): string
    {
        return match ($this) {
            self::CompetencySnapshotNotFound => 'The competency snapshot does not exist.',
            self::CompetencyVersionUnsupported => 'The competency version of this snapshot is not supported by the skill gap version.',
            self::CompetencySnapshotInvalid => 'The competency snapshot does not match the definition of its competency version.',
        };
    }
}
