<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Enums\Competency\CompetencyLevel;
use App\Enums\Growth\GrowthMetricType;
use App\Enums\Growth\GrowthSnapshotStatus;
use App\Enums\Growth\GrowthStatus;
use App\Services\Dna\FixedPoint;
use LogicException;

/**
 * Compares an assessment with its baseline (docs/architecture/growth-tracking-v1.md).
 * A pure function of the two assessments' stored values and the rules: no
 * clock, randomness, network or AI, and no scoring of its own.
 *
 * 1. No baseline: NOT_ESTABLISHED, no observations (never zero growth).
 * 2. Any compatibility field differs: INCOMPARABLE, no observations (never
 *    a delta across versions).
 * 3. Otherwise COMPARED: one observation per metric of either assessment:
 *    - not measured on either side: INSUFFICIENT_EVIDENCE, no delta
 *      (missing, unsupported or unavailable is never zero);
 *    - delta = current − previous;
 *    - evidence quality below the minimum on either side:
 *      INSUFFICIENT_EVIDENCE (the delta is kept, no change is claimed);
 *    - a skill gap opening (NO_GAP -> GAP) or closing (GAP -> NO_GAP):
 *      REGRESSED / IMPROVED;
 *    - |delta| >= meaningful delta in the better direction: IMPROVED; in
 *      the worse direction: REGRESSED; otherwise UNCHANGED.
 *    A competency's level change is recorded alongside; the score decides.
 */
final class GrowthEngine
{
    public function compare(?GrowthAssessment $previous, GrowthAssessment $current, GrowthRules $rules): GrowthComparison
    {
        if ($previous === null) {
            return new GrowthComparison(GrowthSnapshotStatus::NotEstablished, [], [], self::summary([]));
        }
        $differences = array_values(array_filter(
            GrowthRules::COMPATIBILITY,
            fn (string $field): bool => ($previous->versions[$field] ?? null) !== ($current->versions[$field] ?? null),
        ));
        if ($differences !== []) {
            return new GrowthComparison(GrowthSnapshotStatus::Incomparable, [], $differences, self::summary([]));
        }

        $before = [];
        foreach ($previous->metrics as $metric) {
            $before[$metric['type'].':'.$metric['key']] = $metric;
        }
        $after = [];
        foreach ($current->metrics as $metric) {
            $after[$metric['type'].':'.$metric['key']] = $metric;
        }
        $observations = [];
        foreach (array_unique([...array_keys($after), ...array_keys($before)]) as $id) {
            $observations[] = $this->observe($before[$id] ?? null, $after[$id] ?? null, $rules);
        }

        return new GrowthComparison(GrowthSnapshotStatus::Compared, $observations, [], self::summary($observations));
    }

    /**
     * @param  array{type: string, key: string, state: string, value: string|null, evidence_quality: string|null, level: string|null}|null  $previous
     * @param  array{type: string, key: string, state: string, value: string|null, evidence_quality: string|null, level: string|null}|null  $current
     * @return array<string, mixed>
     */
    private function observe(?array $previous, ?array $current, GrowthRules $rules): array
    {
        $metric = $current ?? $previous ?? throw new LogicException('An observation needs at least one side.');
        $type = GrowthMetricType::from($metric['type']);
        $observation = [
            'metric_type' => $type->value,
            'metric_key' => $metric['key'],
            'better' => $type->higherIsBetter() ? 'HIGHER' : 'LOWER',
            'previous_state' => $previous['state'] ?? 'MISSING',
            'current_state' => $current['state'] ?? 'MISSING',
            'previous_value' => null,
            'current_value' => null,
            'delta' => null,
            'previous_level' => $previous['level'] ?? null,
            'current_level' => $current['level'] ?? null,
            'level_change' => self::levelChange($previous['level'] ?? null, $current['level'] ?? null),
            'previous_evidence_quality' => $previous['evidence_quality'] ?? null,
            'current_evidence_quality' => $current['evidence_quality'] ?? null,
            'status' => GrowthStatus::InsufficientEvidence->value,
        ];
        if (! $this->measured($previous, $type, $rules) || ! $this->measured($current, $type, $rules)) {
            return $observation;
        }

        $from = FixedPoint::parse((string) $previous['value']);
        $to = FixedPoint::parse((string) $current['value']);
        $delta = $to - $from;
        $observation['previous_value'] = $previous['value'];
        $observation['current_value'] = $current['value'];
        $observation['delta'] = self::signed($delta);
        $observation['status'] = $this->classify($type, $previous, $current, $delta, $rules)->value;

        return $observation;
    }

    /**
     * @param  array{state: string, value: string|null, evidence_quality: string|null}  $previous
     * @param  array{state: string, value: string|null, evidence_quality: string|null}  $current
     */
    private function classify(GrowthMetricType $type, array $previous, array $current, int $delta, GrowthRules $rules): GrowthStatus
    {
        foreach ([$previous['evidence_quality'], $current['evidence_quality']] as $quality) {
            if ($quality !== null && FixedPoint::parse($quality) < $rules->minimumEvidenceQuality) {
                return GrowthStatus::InsufficientEvidence;
            }
        }
        if ($type === GrowthMetricType::SkillGap && $previous['state'] !== $current['state']) {
            return $current['state'] === 'NO_GAP' ? GrowthStatus::Improved : GrowthStatus::Regressed;
        }
        $better = $type->higherIsBetter() ? $delta : -$delta;
        if ($better >= $rules->meaningfulDelta) {
            return GrowthStatus::Improved;
        }
        if ($better <= -$rules->meaningfulDelta) {
            return GrowthStatus::Regressed;
        }

        return GrowthStatus::Unchanged;
    }

    /**
     * @param  array{state: string, value: string|null}|null  $metric
     */
    private function measured(?array $metric, GrowthMetricType $type, GrowthRules $rules): bool
    {
        return $metric !== null && $metric['value'] !== null && in_array($metric['state'], $rules->measuredStates[$type->value], true);
    }

    private static function levelChange(?string $previous, ?string $current): ?string
    {
        $from = $previous === null ? null : CompetencyLevel::tryFrom($previous);
        $to = $current === null ? null : CompetencyLevel::tryFrom($current);
        if ($from === null || $to === null) {
            return null;
        }

        return match ($to->ordinal() <=> $from->ordinal()) {
            1 => 'UP',
            -1 => 'DOWN',
            default => 'SAME',
        };
    }

    /**
     * A signed fixed-point decimal, e.g. -1100 -> "-0.1100".
     */
    public static function signed(int $units): string
    {
        return ($units < 0 ? '-' : '').FixedPoint::format(abs($units));
    }

    /**
     * Categorical counts per metric type and status, and level changes.
     * There is deliberately no aggregate score.
     *
     * @param  list<array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    private static function summary(array $observations): array
    {
        $counts = [];
        foreach (GrowthMetricType::cases() as $type) {
            $counts[$type->value] = array_fill_keys(array_column(GrowthStatus::cases(), 'value'), 0);
        }
        $levels = ['UP' => 0, 'DOWN' => 0];
        foreach ($observations as $observation) {
            $counts[$observation['metric_type']][$observation['status']]++;
            if (isset($levels[$observation['level_change'] ?? ''])) {
                $levels[$observation['level_change']]++;
            }
        }

        return ['observations' => count($observations), 'statuses' => $counts, 'level_changes' => $levels];
    }
}
