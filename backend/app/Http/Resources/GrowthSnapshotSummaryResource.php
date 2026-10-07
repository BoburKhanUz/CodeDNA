<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GrowthSnapshot;
use App\Services\Growth\GrowthEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One assessment in the growth timeline: its outcome, categorical counts
 * and events. Load it with its observations.
 *
 * @mixin GrowthSnapshot
 */
final class GrowthSnapshotSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'growth_snapshot',
            'project_id' => $this->project_id,
            'status' => $this->status->value,
            'assessed_at' => $this->assessed_at->toIso8601ZuluString(),
            'previous_assessed_at' => $this->previous_assessed_at?->toIso8601ZuluString(),
            'current' => [
                'skill_gap_snapshot_id' => $this->skill_gap_snapshot_id,
                'competency_snapshot_id' => $this->competency_snapshot_id,
                'dna_snapshot_id' => $this->dna_snapshot_id,
                'analysis_run_id' => $this->analysis_run_id,
                'source_snapshot_id' => $this->source_snapshot_id,
            ],
            'previous' => $this->previous_skill_gap_snapshot_id === null ? null : [
                'skill_gap_snapshot_id' => $this->previous_skill_gap_snapshot_id,
                'competency_snapshot_id' => $this->previous_competency_snapshot_id,
                'dna_snapshot_id' => $this->previous_dna_snapshot_id,
                'analysis_run_id' => $this->previous_analysis_run_id,
                'source_snapshot_id' => $this->previous_source_snapshot_id,
            ],
            'summary' => $this->summary,
            'events' => GrowthEvents::from($this->observations),
            'rules_version' => $this->rules_version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
