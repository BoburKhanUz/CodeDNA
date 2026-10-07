<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API responses carry account data (profile, projects, scores, GitHub
 * login): no cache may store them, not even the browser's disk cache on a
 * shared computer (Phase 21). Outermost in the api group, so errors and
 * rate-limit responses get it too.
 */
final class PreventResponseCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
