<?php

declare(strict_types=1);

namespace App\Services\History;

use App\Enums\Growth\GrowthSnapshotStatus;
use App\Models\GrowthObservation;
use App\Models\GrowthSnapshot;
use App\Services\Growth\GrowthEngine;
use App\Services\Growth\GrowthRules;

/**
 * Compares two historical points of the same project
 * (docs/architecture/historical-dna-v1.md#comparison). It is not a second
 * growth engine:
 *
 * - when Phase 18 stored the growth of exactly this pair (the later point's
 *   assessment against its immediate baseline, current rules), the stored
 *   growth snapshot is returned as is (basis GROWTH_SNAPSHOT);
 * - otherwise the same Phase 18 GrowthEngine and rules compare the two
 *   points' stored values in memory (basis GROWTH_RULES). Nothing is stored.
 *
 * Only the layers both points have are compared: a layer either point lacks
 * is UNAVAILABLE, never zero. If any compared layer was measured with
 * different versions or specifications, the whole comparison is
 * INCOMPARABLE, with no observations and no delta.
 */
final readonly class HistoryComparer
{
    public const BASIS_GROWTH_SNAPSHOT = 'GROWTH_SNAPSHOT';

    public const BASIS_GROWTH_RULES = 'GROWTH_RULES';

    public function __construct(private GrowthEngine $engine, private GrowthRules $rules) {}

    /**
     * @param  HistoryPoint  $from  the earlier point
     * @param  HistoryPoint  $to  the later point
     */
    public function compare(HistoryPoint $from, HistoryPoint $to): HistoryComparison
    {
        $layers = array_values(array_intersect($from->layers(), $to->layers()));
        $stored = $this->stored($from, $to);
        if ($stored !== null) {
            return new HistoryComparison(
                status: $stored->status,
                basis: self::BASIS_GROWTH_SNAPSHOT,
                growthSnapshot: $stored,
                layers: $layers,
                differences: $stored->differences,
                summary: $stored->summary,
                observations: $stored->observations->all(),
            );
        }

        $comparison = $this->engine->compare($from->assessment()->only($layers), $to->assessment()->only($layers), $this->rules);
        $observations = [];
        foreach ($comparison->observations as $observation) {
            // Unsaved models: the same casts and events as stored observations.
            $observations[] = (new GrowthObservation)->forceFill($observation);
        }

        return new HistoryComparison(
            status: $comparison->status,
            basis: self::BASIS_GROWTH_RULES,
            growthSnapshot: null,
            layers: $layers,
            differences: $comparison->differences,
            summary: $comparison->summary,
            observations: $observations,
        );
    }

    /**
     * The later point's stored growth, when its baseline is exactly the
     * earlier point's assessment.
     */
    private function stored(HistoryPoint $from, HistoryPoint $to): ?GrowthSnapshot
    {
        $growth = $to->growth;
        if ($growth === null || $from->skillGaps === null || $growth->status === GrowthSnapshotStatus::NotEstablished) {
            return null;
        }
        if ($growth->rules_version !== $this->rules->version || $growth->rules_fingerprint !== $this->rules->fingerprint()) {
            return null;
        }

        return $growth->previous_skill_gap_snapshot_id === $from->skillGaps->id ? $growth : null;
    }
}
