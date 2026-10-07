<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Challenge\ChallengeDifficulty;
use App\Enums\Challenge\ChallengeStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One challenge assigned for one skill gap snapshot (Phase 16): the
 * definition version, the full lineage of the gap it addresses and why it
 * was selected. A practice record only: it never changes the gap, the
 * competency or CodeDNA. Created by App\Actions\Challenge\AssignChallenge;
 * only status, attempt counts and timestamps change afterwards, and a
 * PASSED or FAILED challenge never changes again (also a database trigger).
 *
 * @property string $id
 * @property string $user_id
 * @property string $project_id
 * @property string $skill_gap_snapshot_id
 * @property string $competency_snapshot_id
 * @property string $dna_snapshot_id
 * @property string $analysis_run_id
 * @property string $source_snapshot_id
 * @property string $challenge_definition_id
 * @property string $definition_key
 * @property string $definition_version
 * @property string $competency_key
 * @property ChallengeDifficulty $difficulty
 * @property string $language
 * @property string $selection_version
 * @property string $catalog_version
 * @property string $catalog_fingerprint
 * @property array<string, mixed> $selection
 * @property ChallengeStatus $status
 * @property int $max_attempts
 * @property int $attempts_used
 * @property string|null $last_result
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChallengeInstance extends Model
{
    use HasUlids;

    private const IDENTITY = [
        'user_id', 'project_id', 'skill_gap_snapshot_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id',
        'source_snapshot_id', 'challenge_definition_id', 'definition_key', 'definition_version', 'competency_key', 'difficulty',
        'language', 'selection_version', 'catalog_version', 'catalog_fingerprint', 'selection', 'max_attempts',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $instance): void {
            $from = ChallengeStatus::from((string) $instance->getRawOriginal('status'));
            if ($from->isTerminal() || $instance->isDirty(self::IDENTITY)) {
                throw DomainRuleViolation::immutable($instance, 'updated');
            }
            if ($instance->isDirty('status') && ! $from->canTransitionTo($instance->status)) {
                throw DomainRuleViolation::because("Challenge cannot move from {$from->value} to {$instance->status->value}.");
            }
        });
        static::deleting(static fn (self $instance) => throw DomainRuleViolation::immutable($instance, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'difficulty' => ChallengeDifficulty::class,
            'status' => ChallengeStatus::class,
            'selection' => JsonObject::class,
            'max_attempts' => 'integer',
            'attempts_used' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ChallengeDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ChallengeDefinition::class, 'challenge_definition_id');
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

    /**
     * Attempts, newest first.
     *
     * @return HasMany<ChallengeSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(ChallengeSubmission::class)->orderByDesc('attempt_number');
    }

    /**
     * Unordered, for scoped route binding and paginated lists.
     *
     * @return HasMany<ChallengeSubmission, $this>
     */
    public function challengeSubmissions(): HasMany
    {
        return $this->hasMany(ChallengeSubmission::class);
    }
}
