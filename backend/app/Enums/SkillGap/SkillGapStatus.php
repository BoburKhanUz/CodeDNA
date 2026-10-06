<?php

declare(strict_types=1);

namespace App\Enums\SkillGap;

/**
 * The result for one competency against the target profile
 * (docs/architecture/skill-gap-v1.md#statuses):
 *
 * - GAP: the raw gap reaches the material-gap threshold;
 * - NO_GAP: the competency was assessed and the raw gap is below the
 *   threshold (possibly 0; the raw gap is still stored);
 * - INSUFFICIENT_EVIDENCE, UNSUPPORTED, MISSING: the competency was not
 *   assessed (copied from the competency); no gap is computed, never
 *   "target − 0";
 * - NOT_TARGETED: the target profile defines no target for it.
 */
enum SkillGapStatus: string
{
    case Gap = 'GAP';
    case NoGap = 'NO_GAP';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case Unsupported = 'UNSUPPORTED';
    case Missing = 'MISSING';
    case NotTargeted = 'NOT_TARGETED';

    /** Whether a gap was measured (current and target scores both exist). */
    public function isMeasured(): bool
    {
        return $this === self::Gap || $this === self::NoGap;
    }
}
