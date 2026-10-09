<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Assessment\AssessmentStatus;
use App\Enums\Insights\InsightFailure;
use App\Models\AiInsight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI insight (Phase 29).
 *
 * - "notice" states that the content is AI-generated and non-authoritative.
 * - "output" is the validated interpretation, only when SUCCEEDED.
 * - "evidence" is the catalog it was built from (ids, labels, server-owned
 *   descriptions and deterministic facts), so every reference resolves.
 * - A failure is a fixed code and message only.
 *
 * Never: the prompt, the model's raw response, the lineage of other
 * subjects, keys, URLs, headers or lease data.
 *
 * @mixin AiInsight
 */
final class AiInsightResource extends JsonResource
{
    public const NOTICE = 'AI-generated interpretation of deterministic results. It does not determine or change any score, level, gap, step, test result or measurement.';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = (array) ($this->input['payload'] ?? []);
        $metadata = $this->provider_metadata ?? [];

        return [
            'id' => $this->id,
            'type' => 'ai_insight',
            'project_id' => $this->project_id,
            'kind' => $this->kind->value,
            'subject_id' => $this->subjectId(),
            'status' => $this->status->value,
            'notice' => self::NOTICE,
            'versions' => [
                'insight' => $this->insight_version,
                'input_schema' => $this->input_schema_version,
                'output_schema' => $this->output_schema_version,
                'prompt' => $this->prompt_version,
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
            // Token counts only when the runtime reported them; null means unknown.
            'usage' => [
                'input_tokens' => is_int($metadata['input_tokens'] ?? null) ? $metadata['input_tokens'] : null,
                'output_tokens' => is_int($metadata['output_tokens'] ?? null) ? $metadata['output_tokens'] : null,
                'duration_ms' => is_int($metadata['duration_ms'] ?? null) ? $metadata['duration_ms'] : null,
            ],
            'attempts' => $this->attempts,
            'output' => $this->status === AssessmentStatus::Succeeded ? $this->output : null,
            'evidence' => array_map(fn (array $item): array => [
                'id' => $item['id'] ?? null,
                'kind' => $item['kind'] ?? null,
                'label' => $item['label'] ?? null,
                'description' => $item['description'] ?? null,
                'facts' => (object) ($item['facts'] ?? []),
            ], array_values((array) ($payload['evidence'] ?? []))),
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
        $failure = InsightFailure::tryFrom($code) ?? InsightFailure::InsightFailed;

        return ['code' => $failure->value, 'message' => $failure->message()];
    }
}
