<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 22: CSRF protection as it runs in production. Laravel turns CSRF
 * checking off while unit tests run, so every other test passes without a
 * token; here the middleware Sanctum uses for first-party requests is
 * replaced by one that always checks, and requests carry real session and
 * XSRF cookies (Redis sessions).
 */
final class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    private string $session = '';

    private string $xsrf = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useRedisSessions();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        User::factory()->create(['email' => 'linus@example.com']);

        $cookie = $this->send('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->session = (string) $cookie->getCookie((string) config('session.cookie'), false)?->getValue();
        $this->xsrf = (string) $cookie->getCookie('XSRF-TOKEN', false)?->getValue();
        $this->assertNotSame('', $this->session);
        $this->assertNotSame('', $this->xsrf);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $uri, array $headers = [], array $data = []): TestResponse
    {
        $this->forgetSessionState();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->flushHeaders();
        $client = $this->fromBrowser()->withCredentials()->withHeaders($headers);
        if ($this->session !== '') {
            $client = $client->withUnencryptedCookie((string) config('session.cookie'), $this->session);
        }

        return $client->json($method, $uri, $data);
    }

    private function assertCsrfRejected(TestResponse $response): void
    {
        $response->assertStatus(419)
            ->assertExactJsonStructure(['error' => ['code', 'message', 'request_id']])
            ->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH')
            ->assertJsonPath('error.request_id', $response->headers->get('X-Request-ID'));
    }

    public function test_login_without_a_token_is_refused(): void
    {
        $credentials = ['email' => 'linus@example.com', 'password' => 'password'];

        $this->assertCsrfRejected($this->send('POST', '/api/v1/auth/login', [], $credentials));
        $this->assertCsrfRejected($this->send('POST', '/api/v1/auth/login', ['Sec-Fetch-Site' => 'cross-site'], $credentials));
        $this->assertCsrfRejected($this->send('POST', '/api/v1/auth/login', ['Sec-Fetch-Site' => 'same-site'], $credentials));
    }

    public function test_login_with_a_wrong_token_is_refused(): void
    {
        $this->assertCsrfRejected($this->send('POST', '/api/v1/auth/login', ['X-XSRF-TOKEN' => 'not-the-token'], ['email' => 'linus@example.com', 'password' => 'password']));
        $this->assertCsrfRejected($this->send('POST', '/api/v1/auth/login', ['X-CSRF-TOKEN' => $this->xsrf], ['email' => 'linus@example.com', 'password' => 'password']));
    }

    public function test_login_with_the_token_from_the_cookie_succeeds(): void
    {
        $this->send('POST', '/api/v1/auth/login', ['X-XSRF-TOKEN' => $this->xsrf], ['email' => 'linus@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'linus@example.com');
    }

    public function test_a_same_origin_browser_request_is_accepted_without_the_token(): void
    {
        // Laravel 13 trusts the browser's Sec-Fetch-Site: same-origin (docs/security/security-hardening.md).
        $this->send('POST', '/api/v1/auth/login', ['Sec-Fetch-Site' => 'same-origin'], ['email' => 'linus@example.com', 'password' => 'password'])
            ->assertOk();
    }

    public function test_state_changing_requests_of_a_signed_in_user_need_the_token(): void
    {
        $login = $this->send('POST', '/api/v1/auth/login', ['X-XSRF-TOKEN' => $this->xsrf], ['email' => 'linus@example.com', 'password' => 'password'])->assertOk();
        $this->session = (string) $login->getCookie((string) config('session.cookie'), false)?->getValue();
        $this->xsrf = (string) $login->getCookie('XSRF-TOKEN', false)?->getValue();

        $this->assertCsrfRejected($this->send('POST', '/api/v1/projects', [], ['name' => 'Forged', 'slug' => 'forged', 'source_type' => 'UPLOAD']));
        $this->assertCsrfRejected($this->send('POST', '/api/v1/auth/logout'));
        $this->send('GET', '/api/v1/me')->assertOk();
        $this->send('POST', '/api/v1/projects', ['X-XSRF-TOKEN' => $this->xsrf], ['name' => 'Real', 'slug' => 'real', 'source_type' => 'UPLOAD'])->assertCreated();
        $this->assertDatabaseMissing('projects', ['name' => 'Forged', 'slug' => 'forged', 'source_type' => 'UPLOAD']);
    }
}

/** The production CSRF check, also while unit tests run. */
final class EnforcedCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
