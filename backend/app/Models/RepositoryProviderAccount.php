<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Repositories\RepositoryProviderKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A user's authorization with GitLab or Bitbucket Cloud (Phase 28). The
 * tokens are encrypted at rest with Laravel's encrypter (APP_KEY), hidden
 * from serialization, never logged, queued or returned by the API.
 *
 * @property string $id
 * @property string $user_id
 * @property RepositoryProviderKey $provider
 * @property string $provider_user_id
 * @property string $username
 * @property string $access_token
 * @property Carbon|null $access_token_expires_at
 * @property string|null $refresh_token
 */
class RepositoryProviderAccount extends Model
{
    use HasUlids;

    protected $table = 'repository_provider_accounts';

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => RepositoryProviderKey::class,
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token' => 'encrypted',
        ];
    }
}
