<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me — the authenticated user.
 */
final class MeController extends Controller
{
    public function __invoke(Request $request, Gate $gate): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $gate->authorize('view', $user);

        return new UserResource($user);
    }
}
