<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RoadmapSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A learning roadmap in a list: status, focus and progress. Load it with
 * withCount('completions').
 *
 * @mixin RoadmapSnapshot
 */
final class RoadmapSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $selected = $this->focus['selected'] ?? [];

        return [
            'id' => $this->id,
            'type' => 'learning_roadmap',
            'project_id' => $this->project_id,
            'skill_gap_snapshot_id' => $this->skill_gap_snapshot_id,
            'status' => $this->status->value,
            'focus' => array_values(array_column(is_array($selected) ? $selected : [], 'competency_key')),
            'progress' => ['completed' => (int) ($this->completions_count ?? 0), 'total' => $this->step_count],
            'estimated_minutes' => $this->estimated_minutes,
            'versions' => ['roadmap' => $this->roadmap_version, 'rules' => $this->rules_version],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'superseded_at' => $this->superseded_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
