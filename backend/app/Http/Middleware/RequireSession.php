<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-based endpoints (register, login, logout) only work for requests
 * that Sanctum treats as stateful: browser requests whose Origin/Referer is
 * a trusted first-party domain (SANCTUM_STATEFUL_DOMAINS). Anything else has
 * no session, so it gets a clear 400 instead of an internal error.
 */
final class RequireSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw new ApiException(
                ErrorCode::BadRequest,
                'This endpoint requires a browser session. Send the request from a trusted first-party origin.',
            );
        }

        return $next($request);
    }
}
