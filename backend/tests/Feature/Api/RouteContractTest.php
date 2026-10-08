<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 22 HTTP contract over the whole route table, so routes added later
 * are covered without a new test: every /api/v1 path answers unsupported
 * methods with 405 and protected routes answer anonymous requests with 401,
 * always in the standard envelope whose request ID matches X-Request-ID.
 */
final class RouteContractTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '01k6p0a1b2c3d4e5f6g7h8j9zz';

    /**
     * @return array<string, list<string>> concrete path => allowed methods
     */
    private function paths(): array
    {
        $paths = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = '/'.(string) preg_replace(['/\{step\}/', '/\{installation\}/', '/\{provider\}/', '/\{[A-Za-z]+\}/'], ['ch-first-step', '77', 'fake', self::ID], $route->uri());
            $paths[$path] = array_values(array_unique([...($paths[$path] ?? []), ...$route->methods()]));
        }
        ksort($paths);

        return $paths;
    }

    private function assertEnvelope(TestResponse $response, int $status, string $code, string $context): void
    {
        $this->assertSame($status, $response->getStatusCode(), $context);
        $response->assertExactJsonStructure(['error' => ['code', 'message', 'request_id']]);
        $this->assertSame($code, $response->json('error.code'), $context);
        $this->assertNotNull($response->headers->get('X-Request-ID'), $context);
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('error.request_id'), $context);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), $context);
    }

    public function test_the_route_table_is_the_one_this_contract_was_written_for(): void
    {
        // A sanity floor, not a snapshot: the walk below must really see the API.
        $this->assertGreaterThanOrEqual(45, count($this->paths()));
        $this->assertArrayHasKey('/api/v1/projects/'.self::ID.'/history/compare', $this->paths());
    }

    public function test_every_unsupported_method_answers_405_in_the_envelope(): void
    {
        $user = User::factory()->create();
        $checked = 0;
        foreach ($this->paths() as $path => $allowed) {
            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                if (in_array($method, $allowed, true)) {
                    continue;
                }
                $this->assertEnvelope($this->asUser($user)->json($method, $path), 405, 'METHOD_NOT_ALLOWED', "{$method} {$path}");
                $checked++;
            }
        }
        $this->assertGreaterThan(150, $checked);
    }

    public function test_every_protected_route_answers_401_to_an_anonymous_request(): void
    {
        // The billing webhook is authenticated by the provider's signature, not a session (Phase 23);
        // an invitation link's preview is readable by whoever holds the link (Phase 24).
        $public = ['/api/v1/health', '/api/v1/auth/login', '/api/v1/auth/register', '/api/v1/billing/webhooks/fake',
            '/api/v1/organizations/invitations/'.self::ID];
        foreach ($this->paths() as $path => $allowed) {
            if (in_array($path, $public, true)) {
                continue;
            }
            foreach (array_diff($allowed, ['HEAD']) as $method) {
                $this->flushHeaders();
                $this->assertEnvelope($this->fromBrowser()->json($method, $path), 401, 'AUTHENTICATION_REQUIRED', "{$method} {$path}");
            }
        }
    }

    public function test_unknown_paths_and_malformed_ids_answer_404_in_the_envelope(): void
    {
        $user = User::factory()->create();
        foreach (['/api/v1/nope', '/api/v2/projects', '/api/v1/projects/not-a-ulid', '/api/v1/projects/'.self::ID.'/dna/../../me', '/api/v1/projects/'.strtoupper(self::ID).'x'] as $path) {
            $this->assertEnvelope($this->asUser($user)->getJson($path), 404, 'RESOURCE_NOT_FOUND', $path);
        }
    }
}
