<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21: no cache may store an API response (account data), errors and
 * rate-limit responses included.
 */
final class ResponseHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_are_never_stored(): void
    {
        $user = User::factory()->create();

        foreach ([
            $this->asUser($user)->getJson('/api/v1/me')->assertOk(),
            $this->asUser($user)->getJson('/api/v1/profile')->assertOk(),
            $this->getJson('/api/v1/health'),
        ] as $response) {
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_error_responses_are_never_stored_either(): void
    {
        $this->app['auth']->forgetGuards();
        $unauthenticated = $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertStringContainsString('no-store', (string) $unauthenticated->headers->get('Cache-Control'));

        $user = User::factory()->create();
        $missing = $this->asUser($user)->getJson('/api/v1/projects/01k6p0a1b2c3d4e5f6g7h8j9zz')->assertNotFound();
        $this->assertStringContainsString('no-store', (string) $missing->headers->get('Cache-Control'));
    }
}
