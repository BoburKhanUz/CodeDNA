<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Roadmap\RoadmapStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A learning roadmap generated from one skill gap snapshot (Phase 17,
 * docs/architecture/learning-roadmap-v1.md). A planning record: it never
 * changes the gap, the competency or CodeDNA. Created only by
 * App\Actions\Roadmap\GenerateRoadmap; afterwards only the status moves,
 * once, from ACTIVE to COMPLETED or SUPERSEDED (also a database trigger).
 * Nothing is mass assignable and nothing is deleted.
 *
 * @property string $id
 * @property string $user_id
 * @property string $project_id
 * @property string $skill_gap_snapshot_id
 * @property string $competency_snapshot_id
 * @property string $dna_snapshot_id
 * @property string $analysis_run_id
 * @property string $source_snapshot_id
 * @property string $roadmap_version
 * @property string $rules_version
 * @property string $catalog_fingerprint
 * @property string $rules_fingerprint
 * @property string $roadmap_fingerprint
 * @property string $skill_gap_version
 * @property string $skill_gap_specification_fingerprint
 * @property string $target_profile
 * @property string $target_profile_version
 * @property string $challenge_catalog_version
 * @property string $challenge_catalog_fingerprint
 * @property array<string, mixed> $focus
 * @property list<array<string, mixed>> $tracks
 * @property int $step_count
 * @property int $estimated_minutes
 * @property RoadmapStatus $status
 * @property string|null $superseded_by_id
 * @property Carbon|null $superseded_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $completions_count
 */
class RoadmapSnapshot extends Model
{
    use HasUlids;

    /** The only columns that change after creation. */
    private const LIFECYCLE = ['status', 'superseded_by_id', 'superseded_at', 'completed_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(static function (self $roadmap): void {
            $from = RoadmapStatus::from((string) $roadmap->getRawOriginal('status'));
            if ($from->isFinal() || array_diff(array_keys($roadmap->getDirty()), self::LIFECYCLE) !== []) {
                throw DomainRuleViolation::immutable($roadmap, 'updated');
            }
        });
        static::deleting(static fn (self $roadmap) => throw DomainRuleViolation::immutable($roadmap, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RoadmapStatus::class,
            'focus' => JsonObject::class,
            'tracks' => 'array',
            'step_count' => 'integer',
            'estimated_minutes' => 'integer',
            'superseded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Steps in learning order.
     *
     * @return HasMany<RoadmapStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RoadmapStep::class)->orderBy('position');
    }

    /**
     * @return HasMany<RoadmapStepCompletion, $this>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(RoadmapStepCompletion::class);
    }

    /**
     * @return BelongsTo<SkillGapSnapshot, $this>
     */
    public function skillGapSnapshot(): BelongsTo
    {
        return $this->belongsTo(SkillGapSnapshot::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
