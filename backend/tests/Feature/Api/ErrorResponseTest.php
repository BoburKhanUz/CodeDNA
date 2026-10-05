<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ErrorResponseTest extends TestCase
{
    public function test_unknown_routes_return_a_not_found_error(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertNotFound()->assertExactJson(['error' => [
            'code' => 'RESOURCE_NOT_FOUND',
            'message' => 'The requested resource was not found.',
            'request_id' => $response->headers->get('X-Request-ID'),
        ]]);
    }

    public function test_unsupported_methods_return_method_not_allowed(): void
    {
        $this->getJson('/api/v1/auth/login')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_authorization_denials_return_forbidden(): void
    {
        Gate::define('test-always-deny', static fn (): bool => false);
        Route::middleware('api')->get('/api/v1/__test/forbidden', static function (): void {
            Gate::authorize('test-always-deny');
        });

        $this->getJson('/api/v1/__test/forbidden')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.message', 'This action is not allowed.');
    }

    public function test_unexpected_errors_never_leak_internals_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('/api/v1/__test/crash', static function (): void {
            throw new RuntimeException('secret detail at /var/www/backend/app/Secret.php');
        });

        $response = $this->getJson('/api/v1/__test/crash');

        $response->assertStatus(500)->assertExactJson(['error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'An unexpected error occurred.',
            'request_id' => $response->headers->get('X-Request-ID'),
        ]]);
        foreach (['secret detail', '/var/www', 'trace', 'exception', 'Secret.php'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent() ?: '');
        }
    }

    public function test_oversized_bodies_return_payload_too_large(): void
    {
        $this->call('POST', '/api/v1/auth/login', server: [
            'CONTENT_LENGTH' => (string) (1024 * 1024 * 1024),
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_api_errors_are_json_without_an_accept_header(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_a_valid_client_request_id_is_echoed(): void
    {
        $id = '0b6b1a4e-3c1d-4e5f-8a9b-1c2d3e4f5a6b';

        $this->withHeader('X-Request-ID', $id)->getJson('/api/v1/does-not-exist')
            ->assertHeader('X-Request-ID', $id)
            ->assertJsonPath('error.request_id', $id);
    }

    public function test_an_invalid_client_request_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-ID', 'not a uuid <script>')->getJson('/api/v1/health');

        $requestId = (string) $response->headers->get('X-Request-ID');
        $this->assertNotSame('not a uuid <script>', $requestId);
        $this->assertTrue(Str::isUuid($requestId));
    }
}
