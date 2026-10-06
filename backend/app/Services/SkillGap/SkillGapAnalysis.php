<?php

declare(strict_types=1);

namespace App\Services\SkillGap;

use App\Enums\SkillGap\SkillGapSnapshotStatus;

/**
 * The output of SkillGapEngine for one competency snapshot and one skill gap
 * version. Scores and gaps are 4-place decimal strings, never floats.
 */
final readonly class SkillGapAnalysis
{
    /**
     * @param  list<array<string, mixed>>  $results  one per competency: targeted ones in profile order, then untargeted by key
     * @param  array<string, mixed>  $summary  counts per status and priority (no aggregate gap)
     */
    public function __construct(
        public SkillGapSnapshotStatus $status,
        public array $results,
        public array $summary,
    ) {}
}
