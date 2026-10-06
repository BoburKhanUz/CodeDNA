<?php

declare(strict_types=1);

namespace App\Services\Competency;

use App\Enums\Competency\CompetencySnapshotStatus;

/**
 * The output of CompetencyEngine for one DNA snapshot and one competency
 * version. Scores are decimal strings with 4 places, never floats.
 */
final readonly class CompetencyResult
{
    /**
     * @param  list<array<string, mixed>>  $competencies  in specification order
     * @param  array<string, mixed>  $summary  counts per status and level (no aggregate score)
     * @param  list<string>  $languages  measured languages, sorted
     */
    public function __construct(
        public CompetencySnapshotStatus $status,
        public array $competencies,
        public array $summary,
        public array $languages,
    ) {}
}
