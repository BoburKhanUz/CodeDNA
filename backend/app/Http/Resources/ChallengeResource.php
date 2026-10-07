<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Services\Challenge\ChallengeGrader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One challenge (GET /api/v1/projects/{project}/challenges/{challenge}): the
 * exercise (public view of the definition: hidden cases are never
 * included), why it was selected, the gap it addresses with its full
 * lineage, versions and fingerprints, and the most recent attempts as
 * summaries. Never: hidden cases, expected outputs of hidden cases,
 * evaluator internals or storage paths.
 *
 * Set "evaluation_available" before rendering (the controller does).
 *
 * @mixin ChallengeInstance
 */
final class ChallengeResource extends JsonResource
{
    public const NOTICE = 'Completing this challenge does not immediately change your CodeDNA score or skill gap. Reassessment occurs from new code analysis.';

    public const RECENT_ATTEMPTS = 20;

    public bool $evaluationAvailable = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = $this->definition;

        return [
            ...(new ChallengeSummaryResource($this->resource))->toArray($request),
            'notice' => self::NOTICE,
            'evaluation_available' => $this->evaluationAvailable,
            'challenge' => $definition?->data()->publicView(),
            'selection' => $this->selection,
            'gap' => $this->selection['gap'] ?? null,
            'lineage' => [
                'skill_gap_snapshot_id' => $this->skill_gap_snapshot_id,
                'competency_snapshot_id' => $this->competency_snapshot_id,
                'dna_snapshot_id' => $this->dna_snapshot_id,
                'analysis_run_id' => $this->analysis_run_id,
                'source_snapshot_id' => $this->source_snapshot_id,
            ],
            'versions' => [
                'definition' => $this->definition_version,
                'catalog' => $this->catalog_version,
                'selection' => $this->selection_version,
                'evaluation' => ChallengeGrader::VERSION,
            ],
            'fingerprints' => [
                'catalog' => $this->catalog_fingerprint,
                'definition' => $definition?->definition_fingerprint,
                'test_suite' => $definition?->test_suite_fingerprint,
            ],
            'recent_attempts' => $this->relationLoaded('submissions')
                ? $this->submissions->map(fn (ChallengeSubmission $s): array => (new ChallengeSubmissionSummaryResource($s))->toArray($request))->all()
                : [],
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
