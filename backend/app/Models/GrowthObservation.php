<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Growth\GrowthMetricType;
use App\Enums\Growth\GrowthStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One metric compared between two compatible assessments: the stored
 * previous and current values, their delta and its classification.
 * Immutable.
 *
 * @property string $id
 * @property string $growth_snapshot_id
 * @property string $project_id
 * @property string $user_id
 * @property int $position
 * @property GrowthMetricType $metric_type
 * @property string $metric_key
 * @property string $better
 * @property string $previous_state
 * @property string $current_state
 * @property string|null $previous_value
 * @property string|null $current_value
 * @property string|null $delta
 * @property string|null $previous_level
 * @property string|null $current_level
 * @property string|null $level_change
 * @property string|null $previous_evidence_quality
 * @property string|null $current_evidence_quality
 * @property GrowthStatus $status
 * @property Carbon|null $created_at
 */
class GrowthObservation extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $observation) => throw DomainRuleViolation::immutable($observation, 'updated'));
        static::deleting(static fn (self $observation) => throw DomainRuleViolation::immutable($observation, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_type' => GrowthMetricType::class,
            'status' => GrowthStatus::class,
            'position' => 'integer',
            'previous_value' => 'decimal:4',
            'current_value' => 'decimal:4',
            'delta' => 'decimal:4',
            'previous_evidence_quality' => 'decimal:4',
            'current_evidence_quality' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<GrowthSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(GrowthSnapshot::class, 'growth_snapshot_id');
    }
}
