<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/auth/register — create an account and start a session.
 */
final class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUser $registerUser): JsonResponse
    {
        $user = $registerUser->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->session(),
        );

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
