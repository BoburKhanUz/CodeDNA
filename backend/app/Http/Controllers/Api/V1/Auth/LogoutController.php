<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LogoutUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/auth/logout — end the current session (204 No Content).
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request, LogoutUser $logoutUser): Response
    {
        $logoutUser->handle($request->session());

        return new Response(status: 204);
    }
}
