<?php

declare(strict_types=1);

namespace App\Enums\Competency;

/**
 * Why a DNA snapshot cannot be turned into a competency matrix. Messages are
 * safe to show: no IDs, hashes or stored content.
 */
enum CompetencyFailure: string
{
    case DnaSnapshotNotFound = 'DNA_SNAPSHOT_NOT_FOUND';
    case DnaScoringVersionUnsupported = 'DNA_SCORING_VERSION_UNSUPPORTED';
    case DnaSnapshotInvalid = 'DNA_SNAPSHOT_INVALID';

    public function message(): string
    {
        return match ($this) {
            self::DnaSnapshotNotFound => 'The DNA snapshot does not exist.',
            self::DnaScoringVersionUnsupported => 'The DNA scoring version of this snapshot is not supported by the competency version.',
            self::DnaSnapshotInvalid => 'The DNA snapshot does not contain the evidence structure of its scoring version.',
        };
    }
}
