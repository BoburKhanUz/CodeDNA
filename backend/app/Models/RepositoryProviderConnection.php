<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GitHub\GitHubConnectionStatus;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A project's connected GitLab or Bitbucket repository and branch (Phase 28).
 * Shares the GitHub connection lifecycle (ACTIVE → DISCONNECTED). Identity
 * never changes and a disconnected connection is frozen (model and trigger),
 * so it stays the provenance of its imports.
 *
 * @property string $id
 * @property string $project_id
 * @property RepositoryProviderKey $provider
 * @property string $repository_id
 * @property string $repository_full_name
 * @property bool $repository_private
 * @property bool $repository_archived
 * @property string|null $default_branch
 * @property string $branch
 * @property GitHubConnectionStatus $status
 * @property string $connected_by
 * @property string|null $last_imported_commit_sha
 * @property Carbon|null $last_imported_at
 * @property Carbon $metadata_verified_at
 * @property Carbon $connected_at
 * @property Carbon|null $disconnected_at
 */
class RepositoryProviderConnection extends Model
{
    use HasUlids;

    protected $table = 'repository_provider_connections';

    protected static function booted(): void
    {
        static::updating(static function (self $connection): void {
            if ($connection->getRawOriginal('status') === GitHubConnectionStatus::Disconnected->value) {
                throw DomainRuleViolation::immutable($connection, 'updated');
            }
        });
        static::deleting(static fn (self $connection) => throw DomainRuleViolation::immutable($connection, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => RepositoryProviderKey::class,
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
}
