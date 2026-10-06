<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\DeveloperProfile;

/**
 * Applies validated profile changes (UpdateDeveloperProfileRequest).
 *
 * Only the profile's fillable fields can change: the owner and internal
 * metadata are not mass assignable.
 */
final readonly class UpdateDeveloperProfile
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(DeveloperProfile $profile, array $changes): DeveloperProfile
    {
        $profile->fill($changes)->save();

        return $profile;
    }
}
