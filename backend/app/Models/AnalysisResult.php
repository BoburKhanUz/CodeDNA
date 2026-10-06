<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * The verified analyzer result of one SUCCEEDED analysis run
 * (docs/architecture/data-flow.md#persistence-of-a-result). One per run,
 * immutable: created by App\Actions\Analysis\PersistAnalysisResult in the
 * same transaction that marks the run SUCCEEDED, never updated or deleted.
 *
 * `result` is the analyzer's response body after signature, schema, identity
 * and result_hash verification, stored as received (JSONB keeps numbers'
 * scale, so 1.0 stays 1.0 and the stored result can be re-verified against
 * result_hash at any time). It contains structure and counts only, never
 * source text (internal analyzer contract). It is a JSON string; decoded()
 * returns it with JSON objects as objects.
 *
 * @property string $analysis_run_id
 * @property AnalysisResultType $result_type
 * @property string $result_hash
 * @property string $contract_version
 * @property string $analyzer_version
 * @property string $ir_version
 * @property string|null $metrics_version
 * @property string $result JSON text of the verified analyzer response
 * @property int $size_bytes
 * @property Carbon|null $created_at
 */
class AnalysisResult extends Model
{
    /** Immutable: there is no updated_at column. */
    public const UPDATED_AT = null;

    protected $primaryKey = 'analysis_run_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(static function (self $result): void {
            $run = AnalysisRun::query()->whereKey($result->analysis_run_id)->toBase()->first(['status', 'result_hash', 'result_type']);

            if ($run === null || $run->status !== AnalysisRunStatus::Succeeded->value) {
                throw DomainRuleViolation::because('An analysis result can only be stored for a SUCCEEDED analysis run.');
            }
            if ($run->result_hash !== $result->result_hash || $run->result_type !== $result->result_type->value) {
                throw DomainRuleViolation::because('An analysis result must match its run\'s result_hash and result_type.');
            }
        });
        static::updating(static fn (self $result) => throw DomainRuleViolation::immutable($result, 'updated'));
        static::deleting(static fn (self $result) => throw DomainRuleViolation::immutable($result, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result_type' => AnalysisResultType::class,
            'size_bytes' => 'integer',
        ];
    }

    /**
     * The stored result decoded the way AnalyzerClient verified it (objects
     * stay objects), e.g. to recompute result_hash with CanonicalJson.
     */
    public function decoded(): stdClass
    {
        $decoded = json_decode($this->result, false, 512, JSON_THROW_ON_ERROR);

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }

    /**
     * @return BelongsTo<AnalysisRun, $this>
     */
    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class);
    }
}
