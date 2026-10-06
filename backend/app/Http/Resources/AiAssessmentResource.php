<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Assessment\AssessmentFailure;
use App\Enums\Assessment\AssessmentStatus;
use App\Models\AiAssessment;
use App\Services\Assessment\AssessmentInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI assessment (GET /api/v1/projects/{project}/assessments/{assessment}).
 *
 * - "notice" states that the content is AI-generated and non-authoritative.
 * - "output" is the validated interpretation, only when SUCCEEDED.
 * - "evidence" is the catalog the interpretation was built from (the
 *   stored input payload: ids, labels, server-owned descriptions and the
 *   deterministic facts), so every evidence reference can be resolved.
 * - lineage, versions, fingerprints, provider and model identify exactly
 *   what produced it. A failure is a fixed code and message only.
 *
 * Never: the prompt, the provider response, keys, headers or any lease data.
 *
 * @mixin AiAssessment
 */
final class AiAssessmentResource extends JsonResource
{
    public const NOTICE = 'AI-generated interpretation of the deterministic results. It does not determine or change any score, level, gap, priority or target.';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $input = AssessmentInput::fromStored($this->input ?? []);
        $metadata = $this->provider_metadata ?? [];

        return [
            'id' => $this->id,
            'type' => 'ai_assessment',
            'project_id' => $this->project_id,
            'status' => $this->status->value,
            'notice' => self::NOTICE,
            'lineage' => [
                'skill_gap_snapshot_id' => $this->skill_gap_snapshot_id,
                'competency_snapshot_id' => $this->competency_snapshot_id,
                'dna_snapshot_id' => $this->dna_snapshot_id,
                'analysis_run_id' => $this->analysis_run_id,
                'source_snapshot_id' => $this->source_snapshot_id,
            ],
            'versions' => [
                'assessment' => $this->assessment_version,
                'input_schema' => $this->input_schema_version,
                'output_schema' => $this->output_schema_version,
                'prompt' => $this->prompt_version,
                'dna_scoring' => $this->dna_scoring_version,
                'competency' => $this->competency_version,
                'skill_gap' => $this->skill_gap_version,
                'target_profile' => $input->payload['versions']['target_profile'] ?? null,
                'target_profile_version' => $input->payload['versions']['target_profile_version'] ?? null,
            ],
            'fingerprints' => [
                'specification' => $this->specification_fingerprint,
                'prompt' => $this->prompt_fingerprint,
                'input' => $this->input_fingerprint,
                'output' => $this->output_fingerprint,
            ],
            'provider' => [
                'name' => $this->provider,
                'model' => $this->model,
                'served_model' => is_string($metadata['served_model'] ?? null) ? $metadata['served_model'] : null,
            ],
            'attempts' => $this->attempts,
            'output' => $this->status === AssessmentStatus::Succeeded ? $this->output : null,
            'evidence' => array_map(fn (array $item): array => [
                'id' => $item['id'] ?? null,
                'kind' => $item['kind'] ?? null,
                'label' => $item['label'] ?? null,
                'description' => $item['description'] ?? null,
                'facts' => (object) ($item['facts'] ?? []),
            ], $input->evidence()),
            'failure' => self::failure($this->failure_code),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{code: string, message: string}|null
     */
    public static function failure(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }
        $failure = AssessmentFailure::tryFrom($code) ?? AssessmentFailure::AssessmentFailed;

        return ['code' => $failure->value, 'message' => $failure->message()];
    }
}
