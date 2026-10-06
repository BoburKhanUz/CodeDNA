<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/auth/password';

    private const CURRENT = 'password';

    private const NEW = 'brand-new-secret-7';

    /** Values that must never be echoed back by the API. */
    private const WRONG_GUESS = 'wrong-guess-123';

    private const UNCONFIRMED = 'new-but-unconfirmed-9';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email' => 'hedy@example.com']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function change(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user, 'web')->fromBrowser()->patchJson(self::URL, array_merge([
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ], $overrides));
    }

    private function storedHash(): string
    {
        return (string) User::query()->whereKey($this->user->id)->value('password');
    }

    public function test_requires_authentication(): void
    {
        $this->fromBrowser()->patchJson(self::URL, [
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
    }

    public function test_changes_the_password_hashes_it_and_ends_the_session(): void
    {
        $oldHash = $this->storedHash();
        $oldRememberToken = $this->user->remember_token;
        Log::spy();

        $response = $this->change();

        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
        $hash = $this->storedHash();
        $this->assertNotSame($oldHash, $hash);
        $this->assertNotSame(self::NEW, $hash);
        $this->assertSame('bcrypt', Hash::info($hash)['algoName']);
        $this->assertTrue(Hash::check(self::NEW, $hash));
        $this->assertNotSame($oldRememberToken, $this->user->refresh()->remember_token);
        $this->assertGuest('web');
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Password changed.' && $context === ['user_id' => $this->user->id],
        );
    }

    public function test_the_old_password_stops_working_and_the_new_one_works(): void
    {
        $this->change()->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->fromBrowser()->postJson('/api/v1/auth/login', ['email' => 'hedy@example.com', 'password' => self::CURRENT])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->fromBrowser()->postJson('/api/v1/auth/login', ['email' => 'hedy@example.com', 'password' => self::NEW])
            ->assertOk();
    }

    public function test_rejects_a_wrong_current_password(): void
    {
        $hash = $this->storedHash();

        $this->change(['current_password' => 'not-my-password'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.fields.current_password.0', 'The current password is incorrect.');
        $this->assertSame($hash, $this->storedHash());
        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_requires_the_current_password(): void
    {
        $this->change(['current_password' => null])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['current_password']]]]);
    }

    public function test_requires_a_matching_confirmation(): void
    {
        $hash = $this->storedHash();

        $this->change(['password_confirmation' => null])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
        $this->change(['password_confirmation' => 'something-else-1'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
        $this->assertSame($hash, $this->storedHash());
    }

    public function test_enforces_the_registration_password_policy(): void
    {
        foreach (['short', str_repeat('a', 73)] as $password) {
            $this->change(['password' => $password, 'password_confirmation' => $password])
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
        }
        $this->assertTrue(Hash::check(self::CURRENT, $this->storedHash()));
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        $this->change(['password' => self::CURRENT, 'password_confirmation' => self::CURRENT])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.password.0', 'The new password must be different from the current password.');
    }

    public function test_never_returns_password_information(): void
    {
        $response = $this->change(['current_password' => self::WRONG_GUESS, 'password' => self::UNCONFIRMED]);

        $body = $response->getContent() ?: '';
        foreach ([self::WRONG_GUESS, self::UNCONFIRMED, $this->storedHash()] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_is_rate_limited_per_user(): void
    {
        $limit = config('codedna.rate_limits.password_change_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->change(['current_password' => "guess-{$i}-xyz"])->assertUnprocessable();
        }

        // Even the correct password is refused once the limit is reached.
        $this->change()
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
        $this->assertTrue(Hash::check(self::CURRENT, $this->storedHash()));
    }

    public function test_is_stricter_than_the_profile_update_limit(): void
    {
        $limits = config('codedna.rate_limits');

        $this->assertLessThan($limits['profile_update_per_minute'], $limits['password_change_per_minute']);
    }

    public function test_requires_a_first_party_browser_session(): void
    {
        // Without a stateful Origin there is no session, hence no session user.
        $this->actingAs($this->user, 'web')->patchJson(self::URL, [
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertStatus(400)->assertJsonPath('error.code', 'BAD_REQUEST');
        $this->assertTrue(Hash::check(self::CURRENT, $this->storedHash()));
    }
}
