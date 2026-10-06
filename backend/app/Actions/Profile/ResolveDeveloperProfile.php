<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\DeveloperProfile;
use App\Models\User;

/**
 * Returns the user's developer profile.
 *
 * Registration and the Phase 06 migration give every user a profile. A user
 * created some other way (e.g. from the console) gets the default profile on
 * first access instead of an error; the unique user_id constraint makes
 * concurrent first accesses safe (createOrFirst).
 */
final readonly class ResolveDeveloperProfile
{
    public function handle(User $user): DeveloperProfile
    {
        return $user->developerProfile()->createOrFirst();
    }
}
