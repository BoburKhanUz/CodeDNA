<?php

declare(strict_types=1);

namespace App\Enums\Roadmap;

/**
 * Why a competency of the skill gap snapshot is not part of the roadmap
 * (docs/architecture/learning-roadmap-v1.md#development-focus). Only a
 * measured, material gap (status GAP) with a learning track is a learning
 * need; no evidence is never presented as one.
 */
enum FocusExclusion: string
{
    case NoGap = 'NO_GAP';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case Unsupported = 'UNSUPPORTED';
    case Missing = 'MISSING';
    case NotTargeted = 'NOT_TARGETED';
    case NoTrack = 'NO_TRACK';
    case TrackLimit = 'TRACK_LIMIT';
}
