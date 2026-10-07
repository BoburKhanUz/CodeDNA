<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Challenge\SubmissionStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt at a challenge (Phase 16): the submitted source, its hash,
 * the exact definition and test-suite fingerprints it is evaluated against
 * and, once done, the deterministic evaluation. Every attempt is a new row;
 * the source never changes and a terminal submission never changes at all
 * (also a database trigger). Nothing is ever deleted.
 *
 * @property string $id
 * @property string $challenge_instance_id
 * @property string $project_id
 * @property string $user_id
 * @property string $challenge_definition_id
 * @property int $attempt_number
 * @property string $language
 * @property string $source
 * @property string $source_sha256
 * @property int $source_bytes
 * @property string|null $idempotency_key_hash
 * @property SubmissionStatus $status
 * @property string $definition_fingerprint
 * @property string $test_suite_fingerprint
 * @property string $evaluation_version
 * @property string $evaluator
 * @property string|null $evaluator_version
 * @property string|null $runtime
 * @property string|null $execution_status
 * @property array<string, mixed>|null $evaluation
 * @property string|null $evaluation_fingerprint
 * @property int|null $duration_ms
 * @property string|null $failure_code
 * @property string|null $failure_detail
 * @property int $job_attempts
 * @property string|null $claim_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChallengeSubmission extends Model
{
    use HasUlids;

    private const IDENTITY = [
        'challenge_instance_id', 'project_id', 'user_id', 'challenge_definition_id', 'attempt_number', 'language', 'source',
        'source_sha256', 'source_bytes', 'idempotency_key_hash', 'definition_fingerprint', 'test_suite_fingerprint',
        'evaluation_version', 'evaluator',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $submission): void {
            $from = SubmissionStatus::from((string) $submission->getRawOriginal('status'));
            if ($from->isTerminal() || $submission->isDirty(self::IDENTITY)) {
                throw DomainRuleViolation::immutable($submission, 'updated');
            }
            if ($submission->isDirty('status') && ! $from->canTransitionTo($submission->status)) {
                throw DomainRuleViolation::because("Submission cannot move from {$from->value} to {$submission->status->value}.");
            }
        });
        static::deleting(static fn (self $submission) => throw DomainRuleViolation::immutable($submission, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'attempt_number' => 'integer',
            'source_bytes' => 'integer',
            'evaluation' => JsonObject::class,
            'duration_ms' => 'integer',
            'job_attempts' => 'integer',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ChallengeInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChallengeInstance::class, 'challenge_instance_id');
    }

    /**
     * @return BelongsTo<ChallengeDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ChallengeDefinition::class, 'challenge_definition_id');
    }
}
