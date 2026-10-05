<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID (docs/api/README.md#request-ids).
 *
 * A client-supplied X-Request-ID is accepted only if it is a valid UUID;
 * otherwise a new one is generated. The ID is added to the log context
 * (Laravel Context is also propagated to queued jobs), echoed in the
 * response header and included in error bodies.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $requestId = is_string($incoming) && Str::isUuid($incoming)
            ? strtolower($incoming)
            : (string) Str::uuid();

        $request->headers->set(self::HEADER, $requestId);
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
