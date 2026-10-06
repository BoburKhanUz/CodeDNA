<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * READY: an overall score exists. INSUFFICIENT_DATA: too little evidence for
 * an overall score (ADR-004 §4); dimension details may still be present.
 */
enum DnaSnapshotStatus: string
{
    case Ready = 'READY';
    case InsufficientData = 'INSUFFICIENT_DATA';
}
