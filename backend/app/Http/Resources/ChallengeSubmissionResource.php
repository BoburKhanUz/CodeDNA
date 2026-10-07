<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChallengeSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attempt (GET .../challenges/{challenge}/submissions/{submission}), for
 * the owner only: the submitted source and, once evaluated, the
 * deterministic feedback (visible cases with expected and observed values,
 * hidden cases by id and status only, rules and acceptance criteria), with
 * the versions and fingerprints it was evaluated against.
 *
 * @mixin ChallengeSubmission
 */
final class ChallengeSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new ChallengeSubmissionSummaryResource($this->resource))->toArray($request),
            'notice' => ChallengeResource::NOTICE,
            'source' => $this->source,
            'evaluation' => $this->evaluation,
            'versions' => [
                'evaluation' => $this->evaluation_version,
                'evaluator' => $this->evaluator_version,
                'runtime' => $this->runtime,
            ],
            'fingerprints' => [
                'definition' => $this->definition_fingerprint,
                'test_suite' => $this->test_suite_fingerprint,
                'evaluation' => $this->evaluation_fingerprint,
            ],
            'duration_ms' => $this->duration_ms,
            'started_at' => $this->started_at?->toIso8601ZuluString(),
        ];
    }
}
