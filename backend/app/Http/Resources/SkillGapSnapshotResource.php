<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Competency\CompetencyKey;
use App\Enums\SkillGap\GapPriority;
use App\Enums\SkillGap\SkillGapStatus;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use App\Services\Dna\FixedPoint;
use App\Services\SkillGap\SkillGapSpecification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

/**
 * One skill gap snapshot with everything the Skill Gaps page shows
 * (GET /api/v1/projects/{project}/skill-gaps/{snapshot},
 * docs/api/README.md#skill-gaps).
 *
 * Presentation only: current and target scores, raw gaps, materiality,
 * priorities and evidence quality are the stored values, passed through
 * unchanged (4-place decimal strings; null where no gap was measured).
 * Target rationales and thresholds come from the snapshot's own skill gap
 * version. No analyzer payload, storage detail or owner ID.
 *
 * @mixin SkillGapSnapshot
 */
final class SkillGapSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $spec = $this->specification();
        $provenance = $this->provenance ?? [];
        $format = FixedPoint::format(...);

        return [
            'id' => $this->id,
            'type' => 'skill_gap_snapshot',
            'project_id' => $this->project_id,
            'competency_snapshot_id' => $this->competency_snapshot_id,
            'dna_snapshot_id' => $this->dna_snapshot_id,
            'analysis_run_id' => $this->analysis_run_id,
            'source_snapshot_id' => $this->source_snapshot_id,
            'status' => $this->status->value,
            'skill_gap_version' => $this->skill_gap_version,
            'specification_fingerprint' => $this->specification_fingerprint,
            'target_profile' => [
                'key' => $this->target_profile,
                'version' => $this->target_profile_version,
                'description' => $spec?->targetProfile->description,
            ],
            'thresholds' => $spec === null ? null : [
                'material_gap' => $format($spec->materialGapThreshold),
                'priorities' => array_map(fn (string $priority, int $minimum): array => [
                    'priority' => $priority,
                    'minimum_gap' => $format($minimum),
                ], array_keys($spec->priorities), array_values($spec->priorities)),
                'high_priority_minimum_evidence_quality' => $format($spec->highPriorityMinimumEvidenceQuality),
            ],
            'competency_version' => $this->competency_version,
            'dna_scoring_version' => $this->dna_scoring_version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'summary' => self::summary($this->summary ?? []),
            'languages' => is_array($provenance['languages'] ?? null) ? array_values($provenance['languages']) : null,
            'competency_snapshot' => $this->competencySnapshot === null ? null : [
                'id' => $this->competencySnapshot->id,
                'status' => $this->competencySnapshot->status->value,
                'specification_fingerprint' => is_string($provenance['competency_specification_fingerprint'] ?? null)
                    ? $provenance['competency_specification_fingerprint'] : null,
                'created_at' => $this->competencySnapshot->created_at?->toIso8601ZuluString(),
            ],
            'source_snapshot' => $this->sourceSnapshot === null ? null : [
                'id' => $this->sourceSnapshot->id,
                'version' => $this->sourceSnapshot->version,
                'file_count' => $this->sourceSnapshot->file_count,
                'primary_language' => $this->sourceSnapshot->primary_language,
                'created_at' => $this->sourceSnapshot->created_at?->toIso8601ZuluString(),
            ],
            'results' => $this->results->map(fn (SkillGapResult $result): array => $this->result($result, $spec))->all(),
        ];
    }

    /**
     * Stored counts per status and priority, in the enums' fixed order.
     *
     * @param  array<array-key, mixed>  $summary
     * @return array{competencies: int, material_gaps: int, statuses: array<string, int>, priorities: array<string, int>}
     */
    public static function summary(array $summary): array
    {
        $statuses = [];
        foreach (SkillGapStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($summary['statuses'][$status->value] ?? 0);
        }
        $priorities = [];
        foreach (GapPriority::cases() as $priority) {
            $priorities[$priority->value] = (int) ($summary['priorities'][$priority->value] ?? 0);
        }

        return [
            'competencies' => (int) ($summary['competencies'] ?? 0),
            'material_gaps' => (int) ($summary['material_gaps'] ?? 0),
            'statuses' => $statuses,
            'priorities' => $priorities,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function result(SkillGapResult $result, ?SkillGapSpecification $spec): array
    {
        $evidence = $result->evidence ?? [];

        return [
            'competency_key' => $result->competency_key,
            'name' => CompetencyKey::tryFrom($result->competency_key)?->displayName() ?? $result->competency_key,
            'status' => $result->status->value,
            'current_score' => $result->current_score,
            'target_score' => $result->target_score,
            'raw_gap' => $result->raw_gap,
            'material_gap' => $result->material_gap,
            'priority' => $result->priority?->value,
            'priority_capped' => $result->priority_capped,
            'evidence_quality' => $result->evidence_quality,
            'competency_status' => $result->competency_status,
            'current_level' => $result->current_level,
            'target_rationale' => $spec?->targetProfile->rationales[$result->competency_key] ?? null,
            'limitations' => array_map(fn (mixed $l): array => [
                'language' => is_array($l) ? ($l['language'] ?? null) : null,
                'note' => is_array($l) ? ($l['note'] ?? null) : null,
            ], array_values((array) ($evidence['limitations'] ?? []))),
            // Fixed key order: JSONB does not keep it.
            'evidence' => array_map(fn (mixed $e): array => [
                'source' => is_array($e) ? ($e['source'] ?? null) : null,
                'status' => is_array($e) ? ($e['status'] ?? null) : null,
                'value' => is_array($e) ? ($e['value'] ?? null) : null,
                'score' => is_array($e) ? ($e['score'] ?? null) : null,
            ], array_values((array) ($evidence['evidence'] ?? []))),
        ];
    }

    private function specification(): ?SkillGapSpecification
    {
        try {
            $spec = SkillGapSpecification::forVersion($this->skill_gap_version);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $spec->targetProfile->key === $this->target_profile ? $spec : null;
    }
}
