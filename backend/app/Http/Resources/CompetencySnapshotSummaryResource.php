<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CompetencySnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One competency snapshot in a list (GET /api/v1/projects/{project}/competencies):
 * lineage, versions, status and the stored counts per status and level.
 * Competency details are served by the detail endpoint.
 *
 * @mixin CompetencySnapshot
 */
final class CompetencySnapshotSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'competency_snapshot',
            'project_id' => $this->project_id,
            'dna_snapshot_id' => $this->dna_snapshot_id,
            'analysis_run_id' => $this->analysis_run_id,
            'source_snapshot_id' => $this->source_snapshot_id,
            'status' => $this->status->value,
            'competency_version' => $this->competency_version,
            'dna_scoring_version' => $this->dna_scoring_version,
            'summary' => CompetencySnapshotResource::summary($this->summary ?? []),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
