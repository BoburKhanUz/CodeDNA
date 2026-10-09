<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_authenticated_user_without_sensitive_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Margaret Hamilton',
            'email' => 'margaret@example.com',
            'email_verified_at' => '2026-01-02 03:04:05',
        ]);

        $response = $this->actingAs($user, 'web')->fromBrowser()->getJson('/api/v1/me');

        $response->assertOk()->assertExactJson(['data' => [
            'id' => $user->id,
            'type' => 'user',
            'name' => 'Margaret Hamilton',
            'email' => 'margaret@example.com',
            'email_verified_at' => '2026-01-02T03:04:05Z',
            'created_at' => $user->created_at?->toIso8601ZuluString(),
        ]]);
        $this->assertStringNotContainsString($user->password, $response->getContent() ?: '');
    }

    public function test_requires_authentication(): void
    {
        $response = $this->fromBrowser()->getJson('/api/v1/me');

        $response->assertUnauthorized()->assertExactJson(['error' => [
            'code' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Authentication is required.',
            'request_id' => $response->headers->get('X-Request-ID'),
        ]]);
    }

    public function test_unauthenticated_requests_get_json_not_a_redirect(): void
    {
        // No Accept header: API routes still answer with a JSON 401.
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
    }

    public function test_an_unknown_bearer_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
    }

    /**
     * Phase 30: the session check of every page render has its own limiter,
     * so polling that spends the per-user API budget never breaks navigation;
     * it is still limited itself.
     */
    public function test_the_session_check_survives_an_exhausted_api_budget_and_has_its_own_limit(): void
    {
        // The limiters read their limits at boot: the configured defaults are used.
        $api = (int) config('codedna.rate_limits.api_per_minute');
        $session = (int) config('codedna.rate_limits.session_per_minute');
        $user = User::factory()->create();
        for ($i = 0; $i < $api; $i++) {
            $this->actingAs($user, 'web')->fromBrowser()->getJson('/api/v1/projects')->assertOk();
        }
        $this->actingAs($user, 'web')->fromBrowser()->getJson('/api/v1/projects')->assertTooManyRequests();

        for ($i = 0; $i < $session; $i++) {
            $this->actingAs($user, 'web')->fromBrowser()->getJson('/api/v1/me')->assertOk();
        }
        $this->actingAs($user, 'web')->fromBrowser()->getJson('/api/v1/me')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
