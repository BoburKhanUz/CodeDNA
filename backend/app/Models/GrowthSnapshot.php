<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Growth\GrowthSnapshotStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What changed at one assessment compared with the project's preceding one
 * (Phase 18, docs/architecture/growth-tracking-v1.md). Evidence from
 * repeated deterministic assessments only; never a score of its own.
 * Created only by App\Actions\Growth\CalculateGrowthSnapshot; never updated
 * (also refused by a database trigger) or deleted (by the model only).
 * Nothing is mass assignable.
 *
 * @property string $id
 * @property string $user_id
 * @property string $project_id
 * @property string $skill_gap_snapshot_id
 * @property string $competency_snapshot_id
 * @property string $dna_snapshot_id
 * @property string $analysis_run_id
 * @property string $source_snapshot_id
 * @property Carbon $assessed_at
 * @property string|null $previous_skill_gap_snapshot_id
 * @property string|null $previous_competency_snapshot_id
 * @property string|null $previous_dna_snapshot_id
 * @property string|null $previous_analysis_run_id
 * @property string|null $previous_source_snapshot_id
 * @property Carbon|null $previous_assessed_at
 * @property string $rules_version
 * @property string $rules_fingerprint
 * @property array<string, string|null> $versions
 * @property array<string, string|null>|null $previous_versions
 * @property list<string> $differences
 * @property GrowthSnapshotStatus $status
 * @property array<string, mixed> $summary
 * @property Carbon|null $created_at
 */
class GrowthSnapshot extends Model
{
    use HasUlids;

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
            'status' => GrowthSnapshotStatus::class,
            'assessed_at' => 'datetime',
            'previous_assessed_at' => 'datetime',
            'versions' => JsonObject::class,
            'previous_versions' => JsonObject::class,
            'differences' => 'array',
            'summary' => JsonObject::class,
        ];
    }

    /**
     * Observations in a fixed order (DNA, competencies, skill gaps).
     *
     * @return HasMany<GrowthObservation, $this>
     */
    public function observations(): HasMany
    {
        return $this->hasMany(GrowthObservation::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
