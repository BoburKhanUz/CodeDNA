<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Account-level authorization. Until organizations and teams exist, a user
 * may only see their own account. Later phases extend authorization with
 * their own policies (Project, Organization, ...) rather than changing
 * authentication.
 */
final class UserPolicy
{
    public function view(User $actor, User $user): bool
    {
        return $actor->is($user);
    }
}
