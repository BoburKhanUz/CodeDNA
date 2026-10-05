<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;

/**
 * POST /api/v1/auth/login — start a session for valid credentials.
 */
final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuthenticateUser $authenticateUser): UserResource
    {
        $user = $authenticateUser->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->session(),
        );

        return new UserResource($user);
    }
}
