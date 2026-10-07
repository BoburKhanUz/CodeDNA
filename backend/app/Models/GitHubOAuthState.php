<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pending GitHub authorization (Phase 19). Only the SHA-256 of the state
 * is stored; the state itself exists only in the authorization URL. It is
 * bound to one user, expires quickly and is consumed atomically, once.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $project_id
 * @property string $state_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 */
class GitHubOAuthState extends Model
{
    use HasUlids;

    protected $table = 'github_oauth_states';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
