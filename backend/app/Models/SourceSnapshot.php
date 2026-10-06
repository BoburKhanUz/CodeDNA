<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\SourceType;
use App\Exceptions\DomainRuleViolation;
use Database\Factories\SourceSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable reference to the exact source that was analyzed
 * (docs/architecture/data-model.md#source-snapshots). The code itself lives
 * in object storage; this row only says where, what hash and how big.
 *
 * Created only through App\Actions\Snapshots\RecordSourceSnapshot (which
 * assigns the per-project version). Nothing is mass assignable, and updates
 * and deletes through Eloquent are refused.
 *
 * @property string $id
 * @property string $project_id
 * @property int $version
 * @property SourceType $source_type
 * @property string $storage_disk
 * @property string $storage_key
 * @property string $source_hash
 * @property int $size_bytes
 * @property int $file_count
 * @property string|null $primary_language
 * @property array<string, mixed>|null $metadata
 * @property string|null $idempotency_key_hash SHA-256 of the upload's Idempotency-Key header
 * @property Carbon|null $created_at
 */
class SourceSnapshot extends Model
{
    /** @use HasFactory<SourceSnapshotFactory> */
    use HasFactory, HasUlids;

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
            'version' => 'integer',
            'source_type' => SourceType::class,
            'size_bytes' => 'integer',
            'file_count' => 'integer',
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
     * @return HasMany<AnalysisRun, $this>
     */
    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }
}
