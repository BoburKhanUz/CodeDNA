<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/auth/register';

    private const PASSWORD = 'correct-horse-42';

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    public function test_registers_a_user_and_starts_an_authenticated_session(): void
    {
        Event::fake([Registered::class]);

        $response = $this->fromBrowser()->postJson(self::URL, $this->payload());

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $response->assertCreated()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'type' => 'user',
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'email_verified_at' => null,
                'created_at' => $user->created_at?->toIso8601ZuluString(),
            ]]);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(26, strlen($user->id), 'user IDs are ULIDs');
        Event::assertDispatched(Registered::class, fn (Registered $event): bool => $event->user->is($user));
    }

    public function test_stores_the_password_as_a_bcrypt_hash(): void
    {
        $this->fromBrowser()->postJson(self::URL, $this->payload())->assertCreated();

        $stored = User::query()->where('email', 'ada@example.com')->value('password');
        $this->assertIsString($stored);
        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertSame('bcrypt', Hash::info($stored)['algoName']);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored));
    }

    public function test_never_serializes_sensitive_fields(): void
    {
        $response = $this->fromBrowser()->postJson(self::URL, $this->payload())->assertCreated();

        $response->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
        $hash = (string) User::query()->value('password');
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent() ?: '');
        $this->assertStringNotContainsString($hash, $response->getContent() ?: '');
    }

    public function test_normalizes_the_email_to_lowercase(): void
    {
        $this->fromBrowser()->postJson(self::URL, $this->payload(['email' => '  Ada@Example.COM ']))
            ->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_rejects_invalid_input_with_the_standard_validation_format(): void
    {
        $response = $this->fromBrowser()->postJson(self::URL, [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'The given data was invalid.')
            ->assertJsonPath('error.request_id', $response->headers->get('X-Request-ID'))
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['name', 'email', 'password']]]]);
        $this->assertIsList($response->json('error.details.fields.email'));
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_requires_matching_password_confirmation(): void
    {
        $this->fromBrowser()->postJson(self::URL, $this->payload(['password_confirmation' => 'something-else-1']))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
    }

    public function test_rejects_a_duplicate_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->fromBrowser()->postJson(self::URL, $this->payload(['email' => 'ADA@example.com']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.fields.email.0', 'The email has already been taken.');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_requires_a_first_party_browser_session(): void
    {
        // No Origin/Referer from a stateful domain: Sanctum starts no session.
        $this->postJson(self::URL, $this->payload())
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_is_rate_limited_per_ip(): void
    {
        $limit = config('codedna.rate_limits.register_per_minute_per_ip');

        for ($i = 0; $i < $limit; $i++) {
            $this->fromBrowser()->postJson(self::URL, [])->assertUnprocessable();
        }

        $this->fromBrowser()->postJson(self::URL, $this->payload())
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
        $this->assertDatabaseCount('users', 0);
    }
}
