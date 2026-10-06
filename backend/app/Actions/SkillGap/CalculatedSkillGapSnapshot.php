<?php

declare(strict_types=1);

namespace App\Actions\SkillGap;

use App\Models\SkillGapSnapshot;

/**
 * The skill gap snapshot of a competency snapshot for the configured version
 * and target profile, and whether this call created it.
 */
final readonly class CalculatedSkillGapSnapshot
{
    public function __construct(
        public SkillGapSnapshot $snapshot,
        public bool $created,
    ) {}
}
