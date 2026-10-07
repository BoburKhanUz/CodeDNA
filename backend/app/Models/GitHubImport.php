<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GitHub\GitHubImportStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One import of a connected repository's branch into a source snapshot
 * (Phase 19). The commit is resolved by the server from GitHub, never sent
 * by a client. A finished import never changes (model and trigger).
 *
 * @property string $id
 * @property string $github_connection_id
 * @property string $project_id
 * @property string $user_id
 * @property int $repository_id
 * @property string $repository_full_name
 * @property string $ref
 * @property string|null $commit_sha
 * @property GitHubImportStatus $status
 * @property string|null $failure_code
 * @property string|null $source_snapshot_id
 * @property bool $created_snapshot
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GitHubImport extends Model
{
    use HasUlids;

    protected $table = 'github_imports';

    protected static function booted(): void
    {
        static::updating(static function (self $import): void {
            $original = GitHubImportStatus::from((string) $import->getRawOriginal('status'));
            if ($original->isTerminal()) {
                throw DomainRuleViolation::immutable($import, 'updated');
            }
        });
        static::deleting(static fn (self $import) => throw DomainRuleViolation::immutable($import, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'repository_id' => 'integer',
            'status' => GitHubImportStatus::class,
            'created_snapshot' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GitHubConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(GitHubConnection::class, 'github_connection_id');
    }

    /**
     * @return BelongsTo<SourceSnapshot, $this>
     */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class);
    }
}
