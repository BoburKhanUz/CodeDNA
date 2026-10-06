<?php

declare(strict_types=1);

namespace App\Services\SkillGap;

use App\Enums\Competency\CompetencyKey;
use App\Enums\Competency\CompetencyLevel;
use App\Enums\Competency\CompetencyStatus;
use App\Enums\SkillGap\GapPriority;
use App\Enums\SkillGap\SkillGapFailure;
use App\Enums\SkillGap\SkillGapSnapshotStatus;
use App\Enums\SkillGap\SkillGapStatus;
use App\Services\Dna\FixedPoint;
use InvalidArgumentException;

/**
 * Compares a stored competency matrix with a target profile
 * (docs/architecture/skill-gap-v1.md). A pure function of (competency
 * results, specification): no clock, randomness, database, network or AI,
 * and no floating point. Current scores are the competency snapshot's
 * stored scores; nothing is re-scored.
 *
 *     raw_gap  = max(target_score − current_score, 0)
 *     material = raw_gap ≥ material_gap_threshold
 *
 * A competency that was not assessed has no gap (never "target − 0").
 */
final class SkillGapEngine
{
    /**
     * @param  array<array-key, mixed>  $competencies  CompetencySnapshot::$competencies
     *
     * @throws SkillGapException
     */
    public function analyze(array $competencies, SkillGapSpecification $spec): SkillGapAnalysis
    {
        $byKey = [];
        foreach ($competencies as $competency) {
            $key = is_array($competency) && is_string($competency['key'] ?? null) ? $competency['key'] : null;
            if ($key === null || isset($byKey[$key])) {
                throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
            }
            $byKey[$key] = $competency;
        }

        $results = [];
        foreach ($spec->targetProfile->targets as $key => $target) {
            $results[] = $this->result($key, $byKey[$key] ?? null, $target, $spec);
        }
        $untargeted = array_values(array_diff(array_keys($byKey), array_keys($spec->targetProfile->targets)));
        sort($untargeted);
        foreach ($untargeted as $key) {
            $results[] = $this->result($key, $byKey[$key], null, $spec);
        }

        $statuses = array_fill_keys(array_map(fn (SkillGapStatus $s): string => $s->value, SkillGapStatus::cases()), 0);
        $priorities = array_fill_keys(array_map(fn (GapPriority $p): string => $p->value, GapPriority::cases()), 0);
        foreach ($results as $result) {
            $statuses[$result['status']]++;
            if ($result['priority'] !== null) {
                $priorities[$result['priority']]++;
            }
        }

        $status = match (true) {
            $statuses[SkillGapStatus::Gap->value] > 0 => SkillGapSnapshotStatus::GapsIdentified,
            $statuses[SkillGapStatus::NoGap->value] > 0 => SkillGapSnapshotStatus::NoMaterialGaps,
            default => SkillGapSnapshotStatus::InsufficientData,
        };

        return new SkillGapAnalysis(
            status: $status,
            results: $results,
            summary: [
                'competencies' => count($results),
                'material_gaps' => $statuses[SkillGapStatus::Gap->value],
                'statuses' => $statuses,
                'priorities' => $priorities,
            ],
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $competency
     * @param  int|null  $target  null: not targeted
     * @return array<string, mixed>
     */
    private function result(string $key, ?array $competency, ?int $target, SkillGapSpecification $spec): array
    {
        $result = [
            'competency_key' => $key,
            'name' => CompetencyKey::tryFrom($key)?->displayName() ?? (is_string($competency['name'] ?? null) ? $competency['name'] : $key),
            'status' => SkillGapStatus::Missing->value,
            'current_score' => null,
            'target_score' => $target === null ? null : FixedPoint::format($target),
            'raw_gap' => null,
            'material_gap' => null,
            'priority' => null,
            'priority_capped' => null,
            'evidence_quality' => null,
            'competency_status' => null,
            'current_level' => null,
            'limitations' => [],
            'evidence' => [],
        ];
        if ($competency === null) {
            return $result;
        }

        $competencyStatus = CompetencyStatus::tryFrom(is_string($competency['status'] ?? null) ? $competency['status'] : '')
            ?? throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
        $quality = $this->decimal($competency['evidence_quality'] ?? null);
        $result = [
            ...$result,
            'competency_status' => $competencyStatus->value,
            'evidence_quality' => FixedPoint::format($quality),
            'limitations' => $this->limitations($competency['limitations'] ?? []),
            'evidence' => $this->evidence($competency['evidence'] ?? []),
        ];

        if ($competencyStatus !== CompetencyStatus::Assessed) {
            $result['status'] = $target === null ? SkillGapStatus::NotTargeted->value : SkillGapStatus::from($competencyStatus->value)->value;

            return $result;
        }

        $current = $this->decimal($competency['score'] ?? null);
        $level = CompetencyLevel::tryFrom(is_string($competency['level'] ?? null) ? $competency['level'] : '')
            ?? throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
        $result['current_score'] = FixedPoint::format($current);
        $result['current_level'] = $level->value;
        if ($target === null) {
            $result['status'] = SkillGapStatus::NotTargeted->value;

            return $result;
        }

        $rawGap = max($target - $current, 0);
        $material = $rawGap >= $spec->materialGapThreshold;
        $result['raw_gap'] = FixedPoint::format($rawGap);
        $result['material_gap'] = $material;
        $result['status'] = ($material ? SkillGapStatus::Gap : SkillGapStatus::NoGap)->value;
        if ($material) {
            [$priority, $capped] = $spec->priorityFor($rawGap, $quality);
            $result['priority'] = $priority->value;
            $result['priority_capped'] = $capped;
        }

        return $result;
    }

    /**
     * @return list<array{language: string, note: string}>
     */
    private function limitations(mixed $limitations): array
    {
        if (! is_array($limitations)) {
            throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
        }
        $list = [];
        foreach ($limitations as $limitation) {
            if (! is_array($limitation) || ! is_string($limitation['language'] ?? null) || ! is_string($limitation['note'] ?? null)) {
                throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
            }
            $list[] = ['language' => $limitation['language'], 'note' => $limitation['note']];
        }
        usort($list, fn (array $a, array $b): int => $a['language'] <=> $b['language']);

        return $list;
    }

    /**
     * The competency's evidence, reduced to what explains the gap: source,
     * status, measured value and evidence score (all as stored).
     *
     * @return list<array{source: string, status: string|null, value: string|null, score: string|null}>
     */
    private function evidence(mixed $evidence): array
    {
        if (! is_array($evidence)) {
            throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
        }
        $list = [];
        foreach ($evidence as $item) {
            if (! is_array($item) || ! is_string($item['source'] ?? null)) {
                throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
            }
            $list[] = [
                'source' => $item['source'],
                'status' => is_string($item['status'] ?? null) ? $item['status'] : null,
                'value' => is_string($item['value'] ?? null) ? $item['value'] : null,
                'score' => is_string($item['score'] ?? null) ? $item['score'] : null,
            ];
        }

        return $list;
    }

    /**
     * A stored 0–1 decimal string in units.
     */
    private function decimal(mixed $value): int
    {
        try {
            $units = is_string($value) ? FixedPoint::parse($value) : -1;
        } catch (InvalidArgumentException) {
            $units = -1;
        }
        if ($units < 0 || $units > FixedPoint::ONE) {
            throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
        }

        return $units;
    }
}
