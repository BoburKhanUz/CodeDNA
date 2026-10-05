<?php

use App\Http\Errors\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Liveness probe for the container healthcheck (no dependency checks).
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

        // Sanctum SPA authentication: first-party browser requests get
        // sessions, cookies and CSRF protection (ADR-006).
        $middleware->statefulApi();
        $middleware->throttleApi('api');

        // API clients get a 401 JSON error, never a redirect to a login page.
        $middleware->redirectGuestsTo(static fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(static function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return app(ApiExceptionRenderer::class)->render($e);
            }

            return null;
        });
    })->create();
