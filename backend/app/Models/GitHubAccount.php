<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A CodeDNA user's GitHub authorization (Phase 19): who they are on GitHub
 * and their GitHub App user tokens. The tokens are encrypted at rest with
 * Laravel's encrypter (APP_KEY), hidden from serialization, never logged,
 * never queued and never returned by the API.
 *
 * @property string $id
 * @property string $user_id
 * @property int $github_user_id
 * @property string $login
 * @property string $access_token
 * @property Carbon|null $access_token_expires_at
 * @property string|null $refresh_token
 * @property Carbon|null $refresh_token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GitHubAccount extends Model
{
    use HasUlids;

    protected $table = 'github_accounts';

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_user_id' => 'integer',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token' => 'encrypted',
            'refresh_token_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
