<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Assessment\AssessmentStatus;
use App\Enums\Insights\InsightKind;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One evidence-grounded AI interpretation of a growth snapshot, a learning
 * roadmap or an evaluated challenge submission (Phase 29,
 * docs/architecture/ai-intelligence-v1.md#storage). Non-authoritative: it
 * explains deterministic results and never changes them.
 *
 * Created QUEUED by App\Actions\Insights\RequestInsight; moved to RUNNING,
 * SUCCEEDED or FAILED only by App\Jobs\GenerateInsight and
 * insight:fail-stale. The subject, versions, fingerprints, provider, model
 * and input never change; a SUCCEEDED or FAILED insight never changes at
 * all (also a database trigger) and nothing is deleted. Regeneration
 * creates a new row.
 *
 * @property string $id
 * @property string $user_id
 * @property string $project_id
 * @property InsightKind $kind
 * @property string|null $growth_snapshot_id
 * @property string|null $roadmap_snapshot_id
 * @property string|null $challenge_submission_id
 * @property string $requested_by
 * @property string $insight_version
 * @property string $input_schema_version
 * @property string $output_schema_version
 * @property string $prompt_version
 * @property string $prompt_fingerprint
 * @property string $specification_fingerprint
 * @property string $input_fingerprint
 * @property string $provider
 * @property string $model
 * @property AssessmentStatus $status
 * @property int $attempts
 * @property string|null $claim_token
 * @property Carbon|null $lease_expires_at
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $output
 * @property string|null $output_fingerprint
 * @property array<string, mixed>|null $provider_metadata
 * @property string|null $failure_code
 * @property string|null $failure_detail
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiInsight extends Model
{
    use HasUlids;

    /** Columns fixed at creation. */
    private const IDENTITY = [
        'user_id', 'project_id', 'kind', 'growth_snapshot_id', 'roadmap_snapshot_id', 'challenge_submission_id', 'requested_by',
        'insight_version', 'input_schema_version', 'output_schema_version', 'prompt_version', 'prompt_fingerprint',
        'specification_fingerprint', 'input_fingerprint', 'provider', 'model', 'input',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $insight): void {
            $from = AssessmentStatus::from((string) $insight->getRawOriginal('status'));
            if ($from->isTerminal() || $insight->isDirty(self::IDENTITY)) {
                throw DomainRuleViolation::immutable($insight, 'updated');
            }
            if ($insight->isDirty('status') && ! $from->canTransitionTo($insight->status)) {
                throw DomainRuleViolation::because("AI insight cannot move from {$from->value} to {$insight->status->value}.");
            }
        });
        static::deleting(static fn (self $insight) => throw DomainRuleViolation::immutable($insight, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InsightKind::class,
            'status' => AssessmentStatus::class,
            'attempts' => 'integer',
            'input' => JsonObject::class,
            'output' => JsonObject::class,
            'provider_metadata' => JsonObject::class,
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function subjectId(): string
    {
        return (string) $this->getAttribute($this->kind->subjectColumn());
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
