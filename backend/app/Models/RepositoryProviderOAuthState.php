<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Repositories\RepositoryProviderKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single-use authorization state (Phase 28): only its SHA-256 is stored,
 * bound to one user and one provider, valid for state_ttl_seconds.
 *
 * @property string $id
 * @property string $user_id
 * @property RepositoryProviderKey $provider
 * @property string|null $project_id
 * @property string $state_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class RepositoryProviderOAuthState extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'repository_provider_oauth_states';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => RepositoryProviderKey::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
