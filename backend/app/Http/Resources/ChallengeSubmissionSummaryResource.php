<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Challenge\SubmissionFailure;
use App\Models\ChallengeSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attempt in a list: status, verdict counts and failure. No source and
 * no per-case feedback.
 *
 * @mixin ChallengeSubmission
 */
final class ChallengeSubmissionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $evaluation = $this->evaluation;

        return [
            'id' => $this->id,
            'type' => 'challenge_submission',
            'challenge_id' => $this->challenge_instance_id,
            'attempt_number' => $this->attempt_number,
            'language' => $this->language,
            'status' => $this->status->value,
            'source_bytes' => $this->source_bytes,
            'source_sha256' => $this->source_sha256,
            'tests' => is_array($evaluation) ? ($evaluation['tests'] ?? null) : null,
            'execution_status' => $this->execution_status,
            'failure' => self::failure($this->failure_code),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
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
        $failure = SubmissionFailure::tryFrom($code) ?? SubmissionFailure::EvaluationFailed;

        return ['code' => $failure->value, 'message' => $failure->message()];
    }
}
