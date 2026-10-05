<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session invalidation itself is covered by SessionLifecycleTest.
 */
final class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_out_the_current_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->fromBrowser()
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_requires_authentication(): void
    {
        $this->fromBrowser()->postJson('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
    }
}
