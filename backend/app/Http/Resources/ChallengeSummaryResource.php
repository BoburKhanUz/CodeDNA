<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChallengeInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One challenge in a list (GET /api/v1/projects/{project}/challenges): what
 * it is, the gap it addresses, its status and attempt counts. No definition
 * body, no submissions, no source.
 *
 * @mixin ChallengeInstance
 */
final class ChallengeSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'challenge',
            'project_id' => $this->project_id,
            'skill_gap_snapshot_id' => $this->skill_gap_snapshot_id,
            'competency_key' => $this->competency_key,
            'definition' => [
                'key' => $this->definition_key,
                'version' => $this->definition_version,
                'title' => $this->definition?->title,
            ],
            'difficulty' => $this->difficulty->value,
            'language' => $this->language,
            'status' => $this->status->value,
            'attempts_used' => $this->attempts_used,
            'max_attempts' => $this->max_attempts,
            'last_result' => $this->last_result,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
        ];
    }
}
