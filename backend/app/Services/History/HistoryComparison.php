<?php

declare(strict_types=1);

namespace App\Services\History;

use App\Enums\Growth\GrowthSnapshotStatus;
use App\Models\GrowthObservation;
use App\Models\GrowthSnapshot;

/**
 * The comparison of two historical points: COMPARED or INCOMPARABLE, the
 * layers both points have, and Phase 18 observations (stored, or produced
 * in memory by the Phase 18 engine).
 */
final readonly class HistoryComparison
{
    /**
     * @param  list<string>  $layers  metric types both points have
     * @param  list<string>  $differences  compatibility fields that differ (INCOMPARABLE)
     * @param  array<string, mixed>  $summary  categorical counts; no aggregate score
     * @param  list<GrowthObservation>  $observations
     */
    public function __construct(
        public GrowthSnapshotStatus $status,
        public string $basis,
        public ?GrowthSnapshot $growthSnapshot,
        public array $layers,
        public array $differences,
        public array $summary,
        public array $observations,
    ) {}
}
