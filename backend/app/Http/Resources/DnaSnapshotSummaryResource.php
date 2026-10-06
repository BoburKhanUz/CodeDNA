<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DnaSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One DNA snapshot in a list (GET /api/v1/projects/{project}/dna). Scores are
 * the stored decimal strings with 4 places ("0.8050"), never floats, so the
 * value is exactly what the scoring engine produced. Dimension details are
 * served by the detail endpoint.
 *
 * @mixin DnaSnapshot
 */
final class DnaSnapshotSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'dna_snapshot',
            'project_id' => $this->project_id,
            'analysis_run_id' => $this->analysis_run_id,
            'source_snapshot_id' => $this->source_snapshot_id,
            'source_snapshot_version' => $this->sourceSnapshot?->version,
            'status' => $this->status->value,
            'overall_score' => $this->overall_score,
            'data_quality' => $this->data_quality,
            'scoring_version' => $this->scoring_version,
            'metrics_version' => $this->metrics_version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
