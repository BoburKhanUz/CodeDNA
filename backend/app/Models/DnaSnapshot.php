<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\AnalysisRunStatus;
use App\Enums\DnaSnapshotStatus;
use App\Exceptions\DomainRuleViolation;
use Database\Factories\DnaSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable DNA result of one successful analysis run
 * (docs/architecture/data-model.md#dna-snapshots). New analyses create new
 * snapshots; existing ones are never updated or deleted through Eloquent.
 * Nothing is mass assignable. App\Actions\Dna\CalculateDnaSnapshot creates
 * them: at most one per (analysis run, scoring version).
 *
 * @property string $id
 * @property string $user_id
 * @property string $project_id
 * @property string $analysis_run_id
 * @property string $source_snapshot_id
 * @property string|null $analyzer_version
 * @property string|null $ir_version
 * @property string|null $metrics_version
 * @property string $scoring_version
 * @property string|null $contract_version
 * @property DnaSnapshotStatus $status
 * @property string|null $overall_score decimal string with 4 places, e.g. "0.8125"
 * @property string|null $data_quality decimal string with 4 places (null only on pre-1.0.0 records)
 * @property array<string, mixed> $dimensions
 * @property array<string, mixed>|null $competencies
 * @property list<mixed>|null $strengths
 * @property list<mixed>|null $weaknesses
 * @property array<string, mixed>|null $evidence
 * @property string $result_hash
 * @property Carbon|null $created_at
 */
class DnaSnapshot extends Model
{
    /** @use HasFactory<DnaSnapshotFactory> */
    use HasFactory, HasUlids;

    /** Immutable: there is no updated_at column. */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(static function (self $snapshot): void {
            $runStatus = AnalysisRun::query()->whereKey($snapshot->analysis_run_id)->toBase()->value('status');

            if ($runStatus !== AnalysisRunStatus::Succeeded->value) {
                throw DomainRuleViolation::because('A DNA snapshot can only be created from a SUCCEEDED analysis run.');
            }
        });
        static::updating(static fn (self $snapshot) => throw DomainRuleViolation::immutable($snapshot, 'updated'));
        static::deleting(static fn (self $snapshot) => throw DomainRuleViolation::immutable($snapshot, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DnaSnapshotStatus::class,
            // Decimal string, never a float (ADR-004: no floating-point scores).
            'overall_score' => 'decimal:4',
            'data_quality' => 'decimal:4',
            'dimensions' => JsonObject::class,
            'competencies' => JsonObject::class,
            'strengths' => 'array',
            'weaknesses' => 'array',
            'evidence' => JsonObject::class,
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
