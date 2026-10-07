<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;

/**
 * One deterministic assessment as growth reads it: the stored values of a
 * skill gap snapshot and its competency and DNA snapshots, and the versions
 * they were measured with. Nothing is recalculated.
 */
final readonly class GrowthAssessment
{
    /**
     * @param  array<string, string|null>  $versions  keyed by GrowthRules::COMPATIBILITY
     * @param  list<array{type: string, key: string, state: string, value: string|null, evidence_quality: string|null, level: string|null}>  $metrics
     */
    public function __construct(
        public array $versions,
        public array $metrics,
    ) {}

    /**
     * @param  iterable<SkillGapResult>  $results
     */
    public static function fromSnapshots(SkillGapSnapshot $gaps, CompetencySnapshot $competency, DnaSnapshot $dna, iterable $results): self
    {
        $metrics = [[
            'type' => 'DNA', 'key' => 'OVERALL', 'state' => $dna->status->value, 'value' => $dna->overall_score,
            'evidence_quality' => $dna->data_quality, 'level' => null,
        ]];
        $dimensions = $dna->dimensions;
        ksort($dimensions, SORT_STRING);
        foreach ($dimensions as $key => $dimension) {
            $metrics[] = [
                'type' => 'DNA', 'key' => (string) $key, 'state' => (string) ($dimension['status'] ?? 'UNAVAILABLE'),
                'value' => self::text($dimension['score'] ?? null), 'evidence_quality' => self::text($dimension['data_quality'] ?? null), 'level' => null,
            ];
        }
        foreach ($competency->competencies as $entry) {
            $metrics[] = [
                'type' => 'COMPETENCY', 'key' => (string) $entry['key'], 'state' => (string) $entry['status'],
                'value' => self::text($entry['score'] ?? null), 'evidence_quality' => self::text($entry['evidence_quality'] ?? null),
                'level' => isset($entry['level']) ? (string) $entry['level'] : null,
            ];
        }
        foreach ($results as $result) {
            $metrics[] = [
                'type' => 'SKILL_GAP', 'key' => $result->competency_key, 'state' => $result->status->value,
                'value' => $result->raw_gap, 'evidence_quality' => $result->evidence_quality, 'level' => null,
            ];
        }

        return new self([
            'dna_scoring_version' => $dna->scoring_version,
            'dna_specification_fingerprint' => self::text($dna->evidence['specification_fingerprint'] ?? null),
            'metrics_version' => $dna->metrics_version,
            'competency_version' => $competency->competency_version,
            'competency_specification_fingerprint' => $competency->specification_fingerprint,
            'skill_gap_version' => $gaps->skill_gap_version,
            'skill_gap_specification_fingerprint' => $gaps->specification_fingerprint,
            'target_profile' => $gaps->target_profile,
            'target_profile_version' => $gaps->target_profile_version,
        ], $metrics);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
