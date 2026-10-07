<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A project's connection to one GitHub repository and branch (Phase 19).
 * Repository and installation identity are verified with GitHub when the
 * connection is made and never change; the database refuses it. A
 * disconnected connection is frozen and kept as provenance of its imports.
 *
 * @property string $id
 * @property string $project_id
 * @property string $user_id
 * @property int $installation_id
 * @property int $repository_id
 * @property string $repository_owner
 * @property string $repository_name
 * @property string $repository_full_name
 * @property bool $repository_private
 * @property bool $repository_archived
 * @property string $default_branch
 * @property string $branch
 * @property GitHubConnectionStatus $status
 * @property string|null $last_imported_commit_sha
 * @property Carbon|null $last_imported_at
 * @property Carbon $metadata_verified_at
 * @property Carbon $connected_at
 * @property Carbon|null $disconnected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GitHubConnection extends Model
{
    use HasUlids;

    protected $table = 'github_connections';

    protected static function booted(): void
    {
        static::deleting(static fn (self $connection) => throw DomainRuleViolation::immutable($connection, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'repository_id' => 'integer',
            'repository_private' => 'boolean',
            'repository_archived' => 'boolean',
            'status' => GitHubConnectionStatus::class,
            'last_imported_at' => 'datetime',
            'metadata_verified_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === GitHubConnectionStatus::Active;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<GitHubImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(GitHubImport::class, 'github_connection_id');
    }
}
