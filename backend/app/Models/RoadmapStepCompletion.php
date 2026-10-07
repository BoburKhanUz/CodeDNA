<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A step the developer marked as completed. Self-reported learning
 * progress: it is not evidence and never changes a score, a competency or
 * a gap. Insert-only.
 *
 * @property string $id
 * @property string $roadmap_snapshot_id
 * @property string $roadmap_step_id
 * @property string $project_id
 * @property string $user_id
 * @property Carbon $completed_at
 */
class RoadmapStepCompletion extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static fn (self $completion) => throw DomainRuleViolation::immutable($completion, 'updated'));
        static::deleting(static fn (self $completion) => throw DomainRuleViolation::immutable($completion, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
