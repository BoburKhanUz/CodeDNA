<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\ProjectStatus;
use App\Enums\SourceType;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A developer's CodeDNA project (docs/architecture/data-model.md#projects).
 *
 * Mass assignment covers only the descriptive fields a user may edit. The
 * owner is set through the relationship (`$user->projects()->create(...)`),
 * the status changes only through archive(), and metadata is set
 * explicitly by application code.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $default_branch
 * @property SourceType $source_type
 * @property string|null $repository_url
 * @property string|null $language
 * @property ProjectStatus $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'default_branch', 'source_type', 'repository_url', 'language'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUlids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'status' => ProjectStatus::class,
            'metadata' => JsonObject::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SourceSnapshot, $this>
     */
    public function sourceSnapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class);
    }

    /**
     * @return HasMany<AnalysisRun, $this>
     */
    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }

    /**
     * @return HasMany<DnaSnapshot, $this>
     */
    public function dnaSnapshots(): HasMany
    {
        return $this->hasMany(DnaSnapshot::class);
    }

    /**
     * @return HasMany<CompetencySnapshot, $this>
     */
    public function competencySnapshots(): HasMany
    {
        return $this->hasMany(CompetencySnapshot::class);
    }

    /**
     * @return HasMany<SkillGapSnapshot, $this>
     */
    public function skillGapSnapshots(): HasMany
    {
        return $this->hasMany(SkillGapSnapshot::class);
    }

    /**
     * AI interpretations (Phase 15): non-authoritative, never part of scoring.
     *
     * @return HasMany<AiAssessment, $this>
     */
    public function aiAssessments(): HasMany
    {
        return $this->hasMany(AiAssessment::class);
    }

    /**
     * Assigned coding challenges (Phase 16): practice records, never part of scoring.
     *
     * @return HasMany<ChallengeInstance, $this>
     */
    public function challengeInstances(): HasMany
    {
        return $this->hasMany(ChallengeInstance::class);
    }

    public function isActive(): bool
    {
        return $this->status === ProjectStatus::Active;
    }

    /**
     * ACTIVE -> ARCHIVED. Archived projects keep their full history but
     * accept no new source snapshots.
     */
    public function archive(): void
    {
        $this->status = ProjectStatus::Archived;
        $this->save();
    }

    /**
     * Learning roadmaps (Phase 17): planning records, never part of scoring.
     *
     * @return HasMany<RoadmapSnapshot, $this>
     */
    public function roadmapSnapshots(): HasMany
    {
        return $this->hasMany(RoadmapSnapshot::class);
    }

    /**
     * Growth snapshots (Phase 18): observations over assessments, never part of scoring.
     *
     * @return HasMany<GrowthSnapshot, $this>
     */
    public function growthSnapshots(): HasMany
    {
        return $this->hasMany(GrowthSnapshot::class);
    }
}
