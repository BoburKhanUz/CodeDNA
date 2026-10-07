<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use App\Enums\Roadmap\FocusExclusion;
use App\Services\Dna\FixedPoint;

/**
 * Ranks the actionable gaps of a skill gap snapshot
 * (docs/architecture/learning-roadmap-v1.md#development-focus). A pure
 * function: the same results, catalog and rules always give the same focus.
 * No clock, randomness, network or AI.
 *
 * 1. Actionable: status GAP (a measured, material gap with a priority) and
 *    a track in the catalog. NO_GAP, INSUFFICIENT_EVIDENCE, UNSUPPORTED,
 *    MISSING and NOT_TARGETED are never presented as a learning need.
 * 2. Order: priority (HIGH, MEDIUM, LOW), raw gap (largest first), evidence
 *    quality (highest first, missing last), competency key.
 * 3. The first rules->maxTracks entries are the focus; the rest are
 *    excluded with TRACK_LIMIT.
 *
 * Each focus entry names the criterion that ranked it above the next
 * actionable gap, so the order can be explained.
 */
final class DevelopmentFocusResolver
{
    public const CRITERIA = ['PRIORITY', 'RAW_GAP', 'EVIDENCE_QUALITY', 'COMPETENCY_KEY'];

    /**
     * @param  list<array<string, mixed>>  $results  skill gap results: competency_key, status, priority, priority_capped,
     *                                               current_score, target_score, raw_gap, evidence_quality, current_level
     */
    public function resolve(array $results, RoadmapCatalog $catalog, RoadmapRules $rules): DevelopmentFocus
    {
        $eligible = [];
        $excluded = [];
        foreach ($results as $result) {
            $entry = self::entry($result);
            if (! in_array($entry['status'], $rules->actionableStatuses, true)) {
                $excluded[] = $entry + ['reason' => (FocusExclusion::tryFrom((string) $entry['status']) ?? FocusExclusion::Missing)->value];
            } elseif ($catalog->trackFor((string) $entry['competency_key']) === null) {
                $excluded[] = $entry + ['reason' => FocusExclusion::NoTrack->value];
            } else {
                $eligible[] = $entry;
            }
        }
        usort($eligible, fn (array $a, array $b): int => [...$this->measures($b, $rules), $a['competency_key']] <=> [...$this->measures($a, $rules), $b['competency_key']]);
        usort($excluded, fn (array $a, array $b): int => $a['competency_key'] <=> $b['competency_key']);

        $selected = [];
        $limited = [];
        foreach ($eligible as $i => $entry) {
            $next = $eligible[$i + 1] ?? null;
            $ranked = $entry + [
                'rank' => $i + 1,
                'ranked_above' => $next['competency_key'] ?? null,
                'deciding_criterion' => $next === null ? null : $this->decidingCriterion($entry, $next, $rules),
            ];
            if ($i < $rules->maxTracks) {
                $selected[] = $ranked;
            } else {
                $limited[] = $ranked + ['reason' => FocusExclusion::TrackLimit->value];
            }
        }

        return new DevelopmentFocus($selected, [...$limited, ...$excluded]);
    }

    /**
     * The first ordering criterion on which $first ranks above $second.
     *
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    private function decidingCriterion(array $first, array $second, RoadmapRules $rules): string
    {
        $a = $this->measures($first, $rules);
        $b = $this->measures($second, $rules);
        foreach ($a as $i => $value) {
            if ($value !== $b[$i]) {
                return self::CRITERIA[$i];
            }
        }

        return 'COMPETENCY_KEY';
    }

    /**
     * The descending criteria: priority rank, raw gap and evidence quality
     * (missing evidence quality ranks last), as integers.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: int, 1: int, 2: int}
     */
    private function measures(array $entry, RoadmapRules $rules): array
    {
        return [
            $rules->priorityRank[$entry['priority']] ?? 0,
            FixedPoint::parse((string) $entry['raw_gap']),
            $entry['evidence_quality'] === null ? -1 : FixedPoint::parse((string) $entry['evidence_quality']),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function entry(array $result): array
    {
        return [
            'competency_key' => (string) $result['competency_key'],
            'status' => (string) $result['status'],
            'priority' => $result['priority'] ?? null,
            'priority_capped' => $result['priority_capped'] ?? null,
            'current_score' => $result['current_score'] ?? null,
            'target_score' => $result['target_score'] ?? null,
            'raw_gap' => $result['raw_gap'] ?? null,
            'evidence_quality' => $result['evidence_quality'] ?? null,
            'current_level' => $result['current_level'] ?? null,
        ];
    }
}
