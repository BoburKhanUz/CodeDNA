<?php

declare(strict_types=1);

namespace App\Enums\Growth;

/**
 * The classification of one metric between two compatible assessments
 * (docs/architecture/growth-tracking-v1.md#statuses). It describes the
 * measured code, never a person.
 *
 * - IMPROVED / REGRESSED: a meaningful change in the metric's better or
 *   worse direction (or a skill gap opening or closing);
 * - UNCHANGED: both values measured, the change is below the meaningful
 *   threshold;
 * - INSUFFICIENT_EVIDENCE: one side is not measured (missing, unsupported,
 *   insufficient evidence) or its evidence quality is too low. Never a
 *   regression, and never computed from a missing value.
 */
enum GrowthStatus: string
{
    case Improved = 'IMPROVED';
    case Regressed = 'REGRESSED';
    case Unchanged = 'UNCHANGED';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
}
