<?php

declare(strict_types=1);

namespace App\Enums\Dna;

/**
 * Stable identifiers of the DNA dimensions (docs/architecture/dna-scoring-v1.md#dimensions).
 * An identifier never changes meaning; a dimension that is redefined gets a
 * new scoring version, and a removed one is never reused.
 */
enum DnaDimension: string
{
    case Complexity = 'COMPLEXITY';
    case Structure = 'STRUCTURE';
    case CodeHygiene = 'CODE_HYGIENE';

    public function displayName(): string
    {
        return match ($this) {
            self::Complexity => 'Complexity',
            self::Structure => 'Structure',
            self::CodeHygiene => 'Code hygiene',
        };
    }
}
