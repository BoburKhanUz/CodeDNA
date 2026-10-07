<?php

declare(strict_types=1);

namespace App\Enums\Growth;

/**
 * The outcome of comparing one assessment with the project's preceding one
 * (docs/architecture/growth-tracking-v1.md#baseline).
 *
 * - NOT_ESTABLISHED: there is no preceding assessment. No baseline exists,
 *   which is not zero growth;
 * - INCOMPARABLE: the preceding assessment was measured with different
 *   versions or specifications. No delta is computed, which is not a
 *   regression;
 * - COMPARED: both assessments are compatible; one observation per metric.
 */
enum GrowthSnapshotStatus: string
{
    case NotEstablished = 'NOT_ESTABLISHED';
    case Incomparable = 'INCOMPARABLE';
    case Compared = 'COMPARED';
}
