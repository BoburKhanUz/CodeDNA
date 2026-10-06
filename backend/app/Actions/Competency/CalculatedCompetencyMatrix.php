<?php

declare(strict_types=1);

namespace App\Actions\Competency;

use App\Models\CompetencySnapshot;

/**
 * The competency snapshot of a DNA snapshot for the competency version, and
 * whether this call created it (false: it already existed).
 */
final readonly class CalculatedCompetencyMatrix
{
    public function __construct(
        public CompetencySnapshot $snapshot,
        public bool $created,
    ) {}
}
