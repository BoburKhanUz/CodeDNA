<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Assessment\AssessmentStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AI interpretation of a skill gap snapshot and its lineage
 * (docs/architecture/ai-assessment-v1.md#storage). Non-authoritative: it
 * explains deterministic results and never changes them.
 *
 * Created QUEUED by App\Actions\Assessment\RequestAssessment; moved to
 * RUNNING, SUCCEEDED or FAILED only by App\Jobs\GenerateAssessment and
 * assessment:fail-stale. Lineage, versions, fingerprints, provider, model
 * and input never change after creation; a SUCCEEDED or FAILED assessment
 * never changes at all (also enforced by a database trigger) and nothing is
 * ever deleted. Regeneration creates a new row.
 *
 * @property string $id
 * @property string $user_id
 * @property string $project_id
 * @property string $skill_gap_snapshot_id
 * @property string $competency_snapshot_id
 * @property string $dna_snapshot_id
 * @property string $analysis_run_id
 * @property string $source_snapshot_id
 * @property string $assessment_version
 * @property string $input_schema_version
 * @property string $output_schema_version
 * @property string $prompt_version
 * @property string $prompt_fingerprint
 * @property string $specification_fingerprint
 * @property string $input_fingerprint
 * @property string $dna_scoring_version
 * @property string $competency_version
 * @property string $skill_gap_version
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
class AiAssessment extends Model
{
    use HasUlids;

    /** Columns fixed at creation. */
    private const IDENTITY = [
        'user_id', 'project_id', 'skill_gap_snapshot_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id',
        'source_snapshot_id', 'assessment_version', 'input_schema_version', 'output_schema_version', 'prompt_version',
        'prompt_fingerprint', 'specification_fingerprint', 'input_fingerprint', 'dna_scoring_version', 'competency_version',
        'skill_gap_version', 'provider', 'model', 'input',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $assessment): void {
            $from = AssessmentStatus::from((string) $assessment->getRawOriginal('status'));
            if ($from->isTerminal() || $assessment->isDirty(self::IDENTITY)) {
                throw DomainRuleViolation::immutable($assessment, 'updated');
            }
            if ($assessment->isDirty('status') && ! $from->canTransitionTo($assessment->status)) {
                throw DomainRuleViolation::because("AI assessment cannot move from {$from->value} to {$assessment->status->value}.");
            }
        });
        static::deleting(static fn (self $assessment) => throw DomainRuleViolation::immutable($assessment, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<SkillGapSnapshot, $this>
     */
    public function skillGapSnapshot(): BelongsTo
    {
        return $this->belongsTo(SkillGapSnapshot::class);
    }
}
