<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class CorsTest extends TestCase
{
    private function preflight(string $origin): TestResponse
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login');
    }

    public function test_cors_is_closed_by_default(): void
    {
        $this->preflight('https://evil.example')
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_only_explicitly_configured_origins_are_allowed_and_never_with_a_wildcard(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test']]);

        $this->preflight('https://app.example.test')
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        // With a single allowed origin the header is that fixed origin, so a
        // browser on any other origin is refused; it is never "*" or echoed.
        $evil = $this->preflight('https://evil.example');
        $this->assertNotContains($evil->headers->get('Access-Control-Allow-Origin'), ['*', 'https://evil.example']);
    }
}
