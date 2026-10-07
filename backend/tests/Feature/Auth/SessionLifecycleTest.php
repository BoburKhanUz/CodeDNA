<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end session behavior with real Redis sessions. In-memory session and
 * guard state is discarded between requests, so each request is authenticated
 * only by the session its cookie points to, as in production.
 */
final class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useRedisSessions();
        $this->cookie = (string) config('session.cookie');
        User::factory()->create(['email' => 'linus@example.com']);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function request(string $method, string $uri, ?string $sessionId, array $data = []): TestResponse
    {
        $this->forgetSessionState();
        // Like a separate HTTP request: no user remembered by the guard from
        // the previous one (Sanctum's AuthenticateSession behaves differently
        // when a user is already resolved at the start of the request).
        $this->app['auth']->forgetGuards();
        // Test-client cookies otherwise persist between requests.
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $client = $this->fromBrowser()->withCredentials();
        if ($sessionId !== null) {
            $client = $client->withCookie($this->cookie, $sessionId);
        }

        return $client->json($method, $uri, $data);
    }

    private function sessionIdFrom(TestResponse $response): string
    {
        $id = $response->getCookie($this->cookie)?->getValue();
        $this->assertIsString($id);

        return $id;
    }

    private function sessionExists(string $id): bool
    {
        $this->forgetSessionState();

        return $this->app['session']->driver()->getHandler()->read($id) !== '';
    }

    private function login(?string $sessionId = null): TestResponse
    {
        return $this->request('POST', '/api/v1/auth/login', $sessionId, [
            'email' => 'linus@example.com',
            'password' => 'password',
        ]);
    }

    public function test_the_session_cookie_authenticates_later_requests(): void
    {
        $sessionId = $this->sessionIdFrom($this->login()->assertOk());

        $this->request('GET', '/api/v1/me', $sessionId)
            ->assertOk()
            ->assertJsonPath('data.email', 'linus@example.com');
        $this->request('GET', '/api/v1/me', null)->assertUnauthorized();
        $this->request('GET', '/api/v1/me', Str::random(40))->assertUnauthorized();
    }

    public function test_login_regenerates_the_session_id(): void
    {
        // An anonymous session exists before login (e.g. from /sanctum/csrf-cookie).
        $anonymousId = $this->sessionIdFrom($this->request('GET', '/api/v1/health', null)->assertOk());
        $this->assertTrue($this->sessionExists($anonymousId));

        $authenticatedId = $this->sessionIdFrom($this->login($anonymousId)->assertOk());

        $this->assertNotSame($anonymousId, $authenticatedId, 'session fixation: the ID must change on login');
        $this->assertFalse($this->sessionExists($anonymousId), 'the pre-login session is destroyed');
        $this->request('GET', '/api/v1/me', $anonymousId)->assertUnauthorized();
        $this->request('GET', '/api/v1/me', $authenticatedId)->assertOk();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $sessionId = $this->sessionIdFrom($this->login()->assertOk());

        $response = $this->request('POST', '/api/v1/auth/logout', $sessionId)->assertNoContent();

        $this->assertNotSame($sessionId, $this->sessionIdFrom($response), 'a fresh session ID is issued');
        $this->assertFalse($this->sessionExists($sessionId), 'the authenticated session is destroyed');
        $this->request('GET', '/api/v1/me', $sessionId)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
    }

    private function changePassword(string $sessionId): TestResponse
    {
        return $this->request('PATCH', '/api/v1/auth/password', $sessionId, [
            'current_password' => 'password',
            'password' => 'brand-new-secret-7',
            'password_confirmation' => 'brand-new-secret-7',
        ]);
    }

    public function test_changing_the_password_invalidates_the_current_session(): void
    {
        $sessionId = $this->sessionIdFrom($this->login()->assertOk());

        $response = $this->changePassword($sessionId)->assertNoContent();

        $this->assertNotSame($sessionId, $this->sessionIdFrom($response), 'a fresh session ID is issued');
        $this->assertFalse($this->sessionExists($sessionId), 'the authenticated session is destroyed');
        $this->request('GET', '/api/v1/me', $sessionId)->assertUnauthorized();
        $this->request('GET', '/api/v1/me', $this->sessionIdFrom($response))->assertUnauthorized();
    }

    public function test_changing_the_password_signs_out_other_sessions(): void
    {
        $laptop = $this->sessionIdFrom($this->login()->assertOk());
        $phone = $this->sessionIdFrom($this->login()->assertOk());
        // Each session records the password hash it was authenticated with
        // (Sanctum's AuthenticateSession middleware) on its first request.
        $this->request('GET', '/api/v1/me', $phone)->assertOk();

        $this->changePassword($laptop)->assertNoContent();

        $this->request('GET', '/api/v1/me', $phone)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->request('GET', '/api/v1/me', $phone)->assertUnauthorized();
    }

    /**
     * Phase 21 (audit regression): a session that makes no request between
     * login and another session's password change is rejected too. Laravel's
     * SessionGuard::login() records the password hash at login, so the
     * session is bound from its first moment, not only after its next
     * request (Sanctum's AuthenticateSession alone would do that).
     */
    public function test_changing_the_password_signs_out_a_session_that_was_never_used_again(): void
    {
        $parked = $this->sessionIdFrom($this->login()->assertOk());
        $laptop = $this->sessionIdFrom($this->login()->assertOk());

        $this->changePassword($laptop)->assertNoContent();

        $this->request('GET', '/api/v1/me', $parked)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
    }
}
