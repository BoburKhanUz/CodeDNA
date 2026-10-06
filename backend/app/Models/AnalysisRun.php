<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Support\AnalysisVersions;
use Database\Factories\AnalysisRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One execution of the analysis pipeline against one source snapshot
 * (docs/architecture/data-model.md#analysis-runs).
 *
 * State changes go through markRunning(), markSucceeded(), markFailed() and
 * cancel(). Any Eloquent update is checked against the allowed transitions
 * (AnalysisRunStatus::canTransitionTo). A run in a terminal state is a
 * historical record and refuses every update. Runs are never deleted
 * through Eloquent.
 *
 * @property string $id
 * @property string $project_id
 * @property string $source_snapshot_id
 * @property AnalysisResultType $result_type
 * @property AnalysisRunStatus $status
 * @property string|null $analyzer_version
 * @property string|null $ir_version
 * @property string|null $metrics_version
 * @property string|null $scoring_version
 * @property string|null $contract_version
 * @property string|null $idempotency_key
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property string|null $result_hash
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AnalysisRun extends Model
{
    /** @use HasFactory<AnalysisRunFactory> */
    use HasFactory, HasUlids;

    /** Longest failure message stored (user-safe text only, never raw output). */
    public const MAX_FAILURE_MESSAGE_LENGTH = 1000;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'QUEUED',
        'result_type' => 'foundation',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $run): void {
            $from = AnalysisRunStatus::from((string) $run->getRawOriginal('status'));

            if ($from->isTerminal()) {
                throw DomainRuleViolation::immutable($run, 'updated');
            }

            if ($run->isDirty('status') && ! $from->canTransitionTo($run->status)) {
                throw DomainRuleViolation::invalidTransition($from, $run->status);
            }
        });

        static::deleting(static fn (self $run) => throw DomainRuleViolation::immutable($run, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AnalysisRunStatus::class,
            'result_type' => AnalysisResultType::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => JsonObject::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<SourceSnapshot, $this>
     */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class);
    }

    /**
     * The verified analyzer result (SUCCEEDED runs of Phase 10 and later).
     *
     * @return HasOne<AnalysisResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(AnalysisResult::class);
    }

    /**
     * A DNA snapshot of this run (one exists per scoring version; use
     * dnaSnapshots() when more than one version may have scored the run).
     *
     * @return HasOne<DnaSnapshot, $this>
     */
    public function dnaSnapshot(): HasOne
    {
        return $this->hasOne(DnaSnapshot::class);
    }

    /**
     * @return HasMany<DnaSnapshot, $this>
     */
    public function dnaSnapshots(): HasMany
    {
        return $this->hasMany(DnaSnapshot::class);
    }

    public function markRunning(): void
    {
        $this->status = AnalysisRunStatus::Running;
        $this->started_at = Carbon::now();
        $this->save();
    }

    public function markSucceeded(AnalysisVersions $versions, string $resultHash): void
    {
        $this->forceFill($versions->toAttributes());
        $this->status = AnalysisRunStatus::Succeeded;
        $this->result_hash = $resultHash;
        $this->completed_at = Carbon::now();
        $this->save();
    }

    /**
     * @param  string  $code  contract error code, e.g. SOURCE_TOO_LARGE
     * @param  string|null  $message  user-safe explanation (never raw analyzer or exception output)
     */
    public function markFailed(string $code, ?string $message = null): void
    {
        $now = Carbon::now();
        $this->status = AnalysisRunStatus::Failed;
        $this->failure_code = $code;
        $this->failure_message = $message === null ? null : mb_substr($message, 0, self::MAX_FAILURE_MESSAGE_LENGTH);
        $this->failed_at = $now;
        $this->completed_at = $now;
        $this->save();
    }

    public function cancel(): void
    {
        $this->status = AnalysisRunStatus::Cancelled;
        $this->completed_at = Carbon::now();
        $this->save();
    }
}
