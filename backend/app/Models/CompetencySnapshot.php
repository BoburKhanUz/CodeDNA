<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Competency\CompetencySnapshotStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable competency matrix of one DNA snapshot under one competency
 * version (docs/architecture/competency-matrix-v1.md). Created only by
 * App\Actions\Competency\CalculateCompetencyMatrix; never updated or deleted
 * through Eloquent. Nothing is mass assignable.
 *
 * @property string $id
 * @property string $user_id
 * @property string $project_id
 * @property string $dna_snapshot_id
 * @property string $analysis_run_id
 * @property string $source_snapshot_id
 * @property string $competency_version
 * @property string $dna_scoring_version
 * @property string $specification_fingerprint
 * @property CompetencySnapshotStatus $status
 * @property list<array<string, mixed>> $competencies
 * @property array<string, mixed> $summary
 * @property array<string, mixed> $provenance
 * @property Carbon|null $created_at
 */
class CompetencySnapshot extends Model
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
            'status' => CompetencySnapshotStatus::class,
            'competencies' => 'array',
            'summary' => JsonObject::class,
            'provenance' => JsonObject::class,
        ];
    }

    /**
     * @return BelongsTo<DnaSnapshot, $this>
     */
    public function dnaSnapshot(): BelongsTo
    {
        return $this->belongsTo(DnaSnapshot::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<AnalysisRun, $this>
     */
    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class);
    }

    /**
     * @return BelongsTo<SourceSnapshot, $this>
     */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class);
    }
}
