<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\SkillGap\GapPriority;
use App\Enums\SkillGap\SkillGapStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The gap of one competency in a skill gap snapshot. Immutable; created with
 * its snapshot. Scores and gaps are decimal strings with 4 places.
 *
 * @property string $id
 * @property string $skill_gap_snapshot_id
 * @property string $project_id
 * @property string $user_id
 * @property int $position
 * @property string $competency_key
 * @property SkillGapStatus $status
 * @property string|null $competency_status
 * @property string|null $current_score
 * @property string|null $target_score
 * @property string|null $raw_gap
 * @property bool|null $material_gap
 * @property GapPriority|null $priority
 * @property bool|null $priority_capped
 * @property string|null $evidence_quality
 * @property string|null $current_level
 * @property array<string, mixed> $evidence
 * @property Carbon|null $created_at
 */
class SkillGapResult extends Model
{
    use HasUlids;

    /** Immutable: there is no updated_at column. */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $result) => throw DomainRuleViolation::immutable($result, 'updated'));
        static::deleting(static fn (self $result) => throw DomainRuleViolation::immutable($result, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => SkillGapStatus::class,
            'priority' => GapPriority::class,
            'current_score' => 'decimal:4',
            'target_score' => 'decimal:4',
            'raw_gap' => 'decimal:4',
            'evidence_quality' => 'decimal:4',
            'material_gap' => 'boolean',
            'priority_capped' => 'boolean',
            'evidence' => JsonObject::class,
        ];
    }

    /**
     * @return BelongsTo<SkillGapSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SkillGapSnapshot::class, 'skill_gap_snapshot_id');
    }
}
