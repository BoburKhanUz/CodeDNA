<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Models\GitHubAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Forgets the user's GitHub authorization: the encrypted tokens are deleted.
 * Connections, imports and source snapshots are untouched; new imports need
 * a new authorization.
 */
final class UnlinkGitHubAccount
{
    public function handle(User $user): bool
    {
        $deleted = GitHubAccount::query()->where('user_id', $user->getKey())->delete() > 0;
        if ($deleted) {
            Log::info('github.unlinked', ['user_id' => $user->getKey()]);
        }

        return $deleted;
    }
}
