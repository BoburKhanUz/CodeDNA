<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\ConfigurationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Self-service registration modes (Phase 27,
 * docs/enterprise/configuration-reference.md#registration), enforced on the
 * server whatever the frontend shows.
 */
final class RegistrationModeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/auth/register';

    private function register(string $email): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->fromBrowser()->postJson(self::URL, [
            'name' => 'New User', 'email' => $email, 'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42',
        ]);
    }

    public function test_open_is_the_default_and_accepts_any_domain(): void
    {
        $this->assertSame('open', config('codedna.registration.mode'));
        $this->register('someone@anywhere.example')->assertCreated();
    }

    public function test_closed_refuses_every_new_account_before_validation(): void
    {
        config(['codedna.registration.mode' => 'closed']);

        $this->register('someone@example.com')->assertForbidden()->assertJsonPath('error.code', 'REGISTRATION_CLOSED');
        // Even an invalid request gets the same answer: nothing is validated or revealed.
        $this->fromBrowser()->postJson(self::URL, [])->assertForbidden()->assertJsonPath('error.code', 'REGISTRATION_CLOSED');
        $this->assertSame(0, User::query()->count());
    }

    public function test_closed_keeps_existing_accounts_working(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com', 'password' => 'correct-horse-42']);
        config(['codedna.registration.mode' => 'closed']);

        $this->fromBrowser()->postJson('/api/v1/auth/login', ['email' => 'existing@example.com', 'password' => 'correct-horse-42'])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_restricted_accepts_only_the_listed_domains_exactly(): void
    {
        config(['codedna.registration.mode' => 'restricted', 'codedna.registration.allowed_email_domains' => ['example.com', 'example.org']]);

        $this->register('Dev@Example.COM')->assertCreated();
        $this->register('dev@example.org')->assertCreated();
        foreach (['dev@other.com', 'dev@sub.example.com', 'dev@example.com.evil.test', 'dev@notexample.com'] as $email) {
            $this->register($email)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonPath('error.details.fields.email.0', 'Registration on this installation is limited to approved email domains.');
        }
        $this->assertSame(['dev@example.com', 'dev@example.org'], User::query()->orderBy('email')->pluck('email')->all());
    }

    public function test_the_registration_settings_are_validated(): void
    {
        $validator = new ConfigurationValidator;
        $problems = fn (array $registration): array => $validator->problems(tap(clone config(), fn ($c) => $c->set('codedna.registration', $registration)), 'testing');

        $this->assertContains('REGISTRATION_MODE must be one of: open, restricted, closed.', $problems(['mode' => 'invite', 'allowed_email_domains' => []]));
        $this->assertContains('REGISTRATION_ALLOWED_EMAIL_DOMAINS must list at least one domain when REGISTRATION_MODE is "restricted".', $problems(['mode' => 'restricted', 'allowed_email_domains' => []]));
        $this->assertContains('REGISTRATION_ALLOWED_EMAIL_DOMAINS must list domain names (e.g. example.com), separated by commas.', $problems(['mode' => 'restricted', 'allowed_email_domains' => ['*.example.com']]));
        $this->assertContains('REGISTRATION_ALLOWED_EMAIL_DOMAINS is set but REGISTRATION_MODE is not "restricted"; it would have no effect.', $problems(['mode' => 'open', 'allowed_email_domains' => ['example.com']]));
        $this->assertSame([], array_filter($problems(['mode' => 'restricted', 'allowed_email_domains' => ['example.com']]), fn (string $p): bool => str_contains($p, 'REGISTRATION')));
    }
}
