<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GitHub\GitHubImportStatus;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One import of a connected GitLab or Bitbucket branch into a source
 * snapshot (Phase 28). Shares the GitHub import lifecycle (QUEUED → RUNNING
 * → SUCCEEDED | FAILED | CANCELLED). The commit is resolved by the server
 * from the provider, never sent by a client. A finished import never changes.
 *
 * @property string $id
 * @property string $connection_id
 * @property string $project_id
 * @property RepositoryProviderKey $provider
 * @property string $requested_by
 * @property string $repository_id
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
class RepositoryProviderImport extends Model
{
    use HasUlids;

    protected $table = 'repository_provider_imports';

    protected static function booted(): void
    {
        static::updating(static function (self $import): void {
            if (GitHubImportStatus::from((string) $import->getRawOriginal('status'))->isTerminal()) {
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
            'provider' => RepositoryProviderKey::class,
            'status' => GitHubImportStatus::class,
            'created_snapshot' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SourceSnapshot, $this>
     */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class);
    }
}
