<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Policies\ViewDecisions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Phase 26: a remembered "view" ALLOW lives exactly as long as the HTTP
 * request that decided it (tests/Feature/Organizations/TeamProjectAccessTest
 * covers a membership revoked between two requests end to end).
 */
final class ViewDecisionsTest extends TestCase
{
    private function routed(): Request
    {
        $request = Request::create('/api/v1/projects/x');
        $request->setRouteResolver(fn () => new Route('GET', 'api/v1/projects/{project}', []));

        return $request;
    }

    public function test_an_allow_is_remembered_for_the_rest_of_the_same_request_only(): void
    {
        $decisions = new ViewDecisions($this->app);
        $this->app->instance('request', $first = $this->routed());
        $decisions->remember('user', 'project');
        $this->assertTrue($decisions->allowed('user', 'project'));
        $this->assertFalse($decisions->allowed('other-user', 'project'));
        $this->assertFalse($decisions->allowed('user', 'other-project'));

        // The next request starts empty, even in the same process.
        $this->app->instance('request', $this->routed());
        $this->assertFalse($decisions->allowed('user', 'project'));
        unset($first);
    }

    public function test_nothing_is_remembered_outside_a_routed_request(): void
    {
        // A console command or queue worker keeps one default request for its whole life.
        $decisions = new ViewDecisions($this->app);
        $this->app->instance('request', Request::create('/'));
        $decisions->remember('user', 'project');
        $this->assertFalse($decisions->allowed('user', 'project'));
    }
}
