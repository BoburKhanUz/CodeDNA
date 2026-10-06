<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ChangePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * PATCH /api/v1/auth/password — change the password (204 No Content).
 *
 * The session is invalidated: the client must sign in again.
 */
final class PasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, ChangePassword $changePassword): Response
    {
        /** @var User $user */
        $user = $request->user();

        $changePassword->handle($user, $request->string('password')->toString(), $request->session());

        return new Response(status: 204);
    }
}
