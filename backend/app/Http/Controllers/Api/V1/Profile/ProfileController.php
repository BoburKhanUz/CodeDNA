<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Actions\Profile\ResolveDeveloperProfile;
use App\Actions\Profile\UpdateDeveloperProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateDeveloperProfileRequest;
use App\Http\Resources\DeveloperProfileResource;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;

/**
 * GET and PATCH /api/v1/profile — the authenticated developer's own profile.
 *
 * The profile is always the caller's: there is no profile ID in the URL, so
 * another user's profile cannot be addressed.
 */
final class ProfileController extends Controller
{
    public function show(Request $request, ResolveDeveloperProfile $resolve, Gate $gate): DeveloperProfileResource
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $resolve->handle($user);
        $gate->authorize('view', $profile);

        return new DeveloperProfileResource($profile);
    }

    public function update(
        UpdateDeveloperProfileRequest $request,
        ResolveDeveloperProfile $resolve,
        UpdateDeveloperProfile $update,
        Gate $gate,
    ): DeveloperProfileResource {
        /** @var User $user */
        $user = $request->user();
        $profile = $resolve->handle($user);
        $gate->authorize('update', $profile);

        return new DeveloperProfileResource($update->handle($profile, $request->validated()));
    }
}
