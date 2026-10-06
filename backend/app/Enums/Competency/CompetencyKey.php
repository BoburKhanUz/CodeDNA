<?php

declare(strict_types=1);

namespace App\Enums\Competency;

/**
 * Stable identifiers of the competencies (docs/architecture/competency-matrix-v1.md).
 * An identifier never changes meaning; a redefined competency gets a new
 * competency version, and a removed one is never reused.
 */
enum CompetencyKey: string
{
    case ComplexityManagement = 'COMPLEXITY_MANAGEMENT';
    case FunctionDesign = 'FUNCTION_DESIGN';
    case TypeStructure = 'TYPE_STRUCTURE';
    case CodeHygiene = 'CODE_HYGIENE';

    public function displayName(): string
    {
        return match ($this) {
            self::ComplexityManagement => 'Complexity management',
            self::FunctionDesign => 'Function design',
            self::TypeStructure => 'Type structure',
            self::CodeHygiene => 'Code hygiene',
        };
    }
}
