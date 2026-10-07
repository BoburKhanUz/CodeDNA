<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Roadmap\RoadmapStepType;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a roadmap, copied from the catalog when the roadmap was
 * generated, so that the roadmap stays readable and unchanged whatever
 * happens to the catalog later. Immutable.
 *
 * @property string $id
 * @property string $roadmap_snapshot_id
 * @property string $project_id
 * @property string $user_id
 * @property int $position
 * @property int $track_position
 * @property int $step_position
 * @property string $track_key
 * @property string $competency_key
 * @property string $step_key
 * @property RoadmapStepType $type
 * @property string $title
 * @property string $description
 * @property string $objective
 * @property int $estimated_minutes
 * @property list<string> $prerequisites
 * @property string|null $challenge_key
 * @property string|null $challenge_version
 * @property string|null $challenge_title
 * @property string|null $challenge_difficulty
 * @property Carbon|null $created_at
 */
class RoadmapStep extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $step) => throw DomainRuleViolation::immutable($step, 'updated'));
        static::deleting(static fn (self $step) => throw DomainRuleViolation::immutable($step, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RoadmapStepType::class,
            'position' => 'integer',
            'track_position' => 'integer',
            'step_position' => 'integer',
            'estimated_minutes' => 'integer',
            'prerequisites' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RoadmapSnapshot, $this>
     */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(RoadmapSnapshot::class, 'roadmap_snapshot_id');
    }
}
