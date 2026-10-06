<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SkillGapSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One skill gap snapshot in a list (GET /api/v1/projects/{project}/skill-gaps):
 * lineage, versions, target profile, status and the stored counts per status
 * and priority. Per-competency results are served by the detail endpoint.
 *
 * @mixin SkillGapSnapshot
 */
final class SkillGapSnapshotSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
            'target_profile' => ['key' => $this->target_profile, 'version' => $this->target_profile_version],
            'competency_version' => $this->competency_version,
            'summary' => SkillGapSnapshotResource::summary($this->summary ?? []),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
