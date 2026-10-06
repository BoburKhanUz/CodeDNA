<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI assessment in a list (GET /api/v1/projects/{project}/assessments):
 * status, lineage, versions, provider and model. The input and output are
 * served by the detail endpoint only.
 *
 * @mixin AiAssessment
 */
final class AiAssessmentSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'ai_assessment',
            'project_id' => $this->project_id,
            'skill_gap_snapshot_id' => $this->skill_gap_snapshot_id,
            'status' => $this->status->value,
            'assessment_version' => $this->assessment_version,
            'provider' => $this->provider,
            'model' => $this->model,
            'failure' => AiAssessmentResource::failure($this->failure_code),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
