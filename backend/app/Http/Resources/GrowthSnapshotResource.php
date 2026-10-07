<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GrowthObservation;
use App\Models\GrowthSnapshot;
use App\Services\Growth\GrowthRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One growth snapshot: the outcome, both assessments and their versions,
 * every observation grouped by metric type, events, and learning activity
 * between the two assessments as context only.
 *
 * The controller loads the observations and sets $activity.
 *
 * @mixin GrowthSnapshot
 */
final class GrowthSnapshotResource extends JsonResource
{
    public const NOTICE = 'Growth compares deterministic code assessments only. Completed learning steps and challenges are not growth evidence; only a new code analysis can show change.';

    /** @var array{roadmap_steps_completed: int, challenges_passed: int}|null */
    public ?array $activity = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GrowthRules $rules */
        $rules = app(GrowthRules::class);
        $grouped = ['DNA' => [], 'COMPETENCY' => [], 'SKILL_GAP' => []];
        foreach ($this->observations as $observation) {
            /** @var GrowthObservation $observation */
            $grouped[$observation->metric_type->value][] = [
                'metric_key' => $observation->metric_key,
                'better' => $observation->better,
                'previous_state' => $observation->previous_state,
                'current_state' => $observation->current_state,
                'previous_value' => $observation->previous_value,
                'current_value' => $observation->current_value,
                'delta' => $observation->delta,
                'previous_level' => $observation->previous_level,
                'current_level' => $observation->current_level,
                'level_change' => $observation->level_change,
                'previous_evidence_quality' => $observation->previous_evidence_quality,
                'current_evidence_quality' => $observation->current_evidence_quality,
                'status' => $observation->status->value,
            ];
        }

        return [
            ...(new GrowthSnapshotSummaryResource($this->resource))->toArray($request),
            'notice' => self::NOTICE,
            'versions' => $this->versions,
            'previous_versions' => $this->previous_versions,
            'differences' => $this->differences,
            'rules' => [
                'version' => $this->rules_version,
                'fingerprint' => $this->rules_fingerprint,
                'current' => $this->rules_version === $rules->version && $this->rules_fingerprint === $rules->fingerprint(),
            ],
            'dna' => $grouped['DNA'],
            'competencies' => $grouped['COMPETENCY'],
            'skill_gaps' => $grouped['SKILL_GAP'],
            // Context only: learning activity between the two assessments is never growth evidence.
            'activity' => $this->activity,
        ];
    }
}
