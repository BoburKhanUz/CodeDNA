<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeveloperProfile;
use App\Models\User;

/**
 * Profiles are self-only: a developer may view and edit their own profile.
 * There is no admin access yet. Public profiles, if ever added, get their
 * own endpoint and resource.
 */
final class DeveloperProfilePolicy
{
    public function view(User $actor, DeveloperProfile $profile): bool
    {
        return $actor->getKey() === $profile->user_id;
    }

    public function update(User $actor, DeveloperProfile $profile): bool
    {
        return $actor->getKey() === $profile->user_id;
    }
}
