<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\SkillGap\SkillGapSnapshotStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable skill gap analysis of one competency snapshot against one
 * target profile under one skill gap version
 * (docs/architecture/skill-gap-v1.md). Created only by
 * App\Actions\SkillGap\CalculateSkillGapSnapshot; never updated or deleted
 * through Eloquent. Nothing is mass assignable.
 *
 * @property string $id
 * @property string $user_id
 * @property string $project_id
 * @property string $competency_snapshot_id
 * @property string $dna_snapshot_id
 * @property string $analysis_run_id
 * @property string $source_snapshot_id
 * @property string $skill_gap_version
 * @property string $target_profile
 * @property string $target_profile_version
 * @property string $competency_version
 * @property string $dna_scoring_version
 * @property string $specification_fingerprint
 * @property SkillGapSnapshotStatus $status
 * @property array<string, mixed> $summary
 * @property array<string, mixed> $provenance
 * @property Carbon|null $created_at
 */
class SkillGapSnapshot extends Model
{
    use HasUlids;

    /** Immutable: there is no updated_at column. */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $snapshot) => throw DomainRuleViolation::immutable($snapshot, 'updated'));
        static::deleting(static fn (self $snapshot) => throw DomainRuleViolation::immutable($snapshot, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SkillGapSnapshotStatus::class,
            'summary' => JsonObject::class,
            'provenance' => JsonObject::class,
        ];
    }

    /**
     * One result per competency, in display order.
     *
     * @return HasMany<SkillGapResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(SkillGapResult::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<CompetencySnapshot, $this>
     */
    public function competencySnapshot(): BelongsTo
    {
        return $this->belongsTo(CompetencySnapshot::class);
    }

    /**
     * @return BelongsTo<SourceSnapshot, $this>
     */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
