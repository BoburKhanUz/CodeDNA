<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use WeakMap;

/**
 * Successful "view" decisions of the current HTTP request (Phase 26).
 *
 * A request's form request and its controller both authorize viewing the
 * same project; for a team project each check read the organization and the
 * caller's membership. This remembers an ALLOW for (actor, project) for the
 * rest of that one request.
 *
 * The memory is keyed by the Request object itself (a WeakMap), not by the
 * container's lifetime: every new request, in any process model (PHP-FPM, a
 * long-lived worker, a test making several requests), starts empty, and the
 * entries are freed with the request. A membership revoked between two
 * requests is therefore always seen. Denials are never remembered, and no
 * ability that changes anything uses it: writes always re-read the
 * membership (and lock it where they must).
 */
final class ViewDecisions
{
    /** @var WeakMap<Request, array<string, true>> */
    private WeakMap $allowed;

    public function __construct(private readonly Container $container)
    {
        $this->allowed = new WeakMap;
    }

    public function allowed(string $actor, string $project): bool
    {
        $request = $this->request();

        return $request !== null && isset($this->allowed[$request]["{$actor}|{$project}"]);
    }

    public function remember(string $actor, string $project): void
    {
        $request = $this->request();
        if ($request !== null) {
            $this->allowed[$request] = [...($this->allowed[$request] ?? []), "{$actor}|{$project}" => true];
        }
    }

    private function request(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }
        $request = $this->container->make('request');

        // Only a request the HTTP kernel routed. A console command or queue
        // worker keeps one default request for its whole life: nothing is
        // remembered there, so no decision outlives a job.
        return $request instanceof Request && $request->route() !== null ? $request : null;
    }
}
