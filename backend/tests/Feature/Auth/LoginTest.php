<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/auth/login';

    public function test_logs_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'grace@example.com']);

        $this->fromBrowser()->postJson(self::URL, ['email' => 'grace@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'grace@example.com')
            ->assertJsonMissingPath('data.password');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_email_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'grace@example.com']);

        $this->fromBrowser()->postJson(self::URL, ['email' => 'Grace@Example.com', 'password' => 'password'])
            ->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_rejects_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'grace@example.com']);

        $this->fromBrowser()->postJson(self::URL, ['email' => 'grace@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('error.message', 'These credentials do not match our records.')
            ->assertJsonMissingPath('error.details');
        $this->assertGuest('web');
    }

    public function test_unknown_email_and_wrong_password_are_indistinguishable(): void
    {
        User::factory()->create(['email' => 'grace@example.com']);

        $wrongPassword = $this->fromBrowser()->postJson(self::URL, ['email' => 'grace@example.com', 'password' => 'wrong-password']);
        $unknownEmail = $this->fromBrowser()->postJson(self::URL, ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $this->assertSame($wrongPassword->status(), $unknownEmail->status());
        $this->assertSame(
            collect($wrongPassword->json('error'))->except('request_id')->all(),
            collect($unknownEmail->json('error'))->except('request_id')->all(),
        );
    }

    public function test_validates_input(): void
    {
        $this->fromBrowser()->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'password']]]]);
    }

    public function test_requires_a_first_party_browser_session(): void
    {
        User::factory()->create(['email' => 'grace@example.com']);

        $this->postJson(self::URL, ['email' => 'grace@example.com', 'password' => 'password'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');
    }

    public function test_is_rate_limited_per_email_and_ip(): void
    {
        User::factory()->create(['email' => 'grace@example.com']);
        $limit = config('codedna.rate_limits.login_per_minute_per_email');

        for ($i = 0; $i < $limit; $i++) {
            $this->fromBrowser()->postJson(self::URL, ['email' => 'grace@example.com', 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        // Even the correct password is refused once the limit is reached.
        $this->fromBrowser()->postJson(self::URL, ['email' => 'grace@example.com', 'password' => 'password'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
        $this->assertGuest('web');
    }
}
