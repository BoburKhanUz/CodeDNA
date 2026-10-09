<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Services\Repositories\ProviderError;
use App\Services\Repositories\ProviderException;
use App\Services\Repositories\ProviderHttp;
use App\Services\Repositories\ProviderRepository;
use App\Services\Repositories\ProviderSettings;
use App\Services\Repositories\ProviderTokens;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeProviders;
use Tests\TestCase;

/**
 * The provider HTTP client and adapters (Phase 28,
 * docs/integrations/import-security.md#http-client): exact origins only, no
 * redirects but one to an archive origin, the token only to the provider,
 * bounded bodies, safe error mapping, and malformed provider data refused.
 */
final class ProviderHttpTest extends TestCase
{
    private FakeProviders $providers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->providers = FakeProviders::configure()->fake();
    }

    private function http(RepositoryProviderKey $key = RepositoryProviderKey::Bitbucket): ProviderHttp
    {
        return new ProviderHttp(app(Factory::class), ProviderSettings::fromConfig(config(), $key));
    }

    private function error(callable $call): ?ProviderException
    {
        try {
            $call();
        } catch (ProviderException $e) {
            return $e;
        }

        return null;
    }

    /** @return array<string, array{string, bool}> */
    public static function origins(): array
    {
        return [
            'exact' => ['https://bitbucket.test/x.zip', true],
            'case-insensitive host' => ['https://BITBUCKET.test/x.zip', true],
            'other scheme' => ['http://bitbucket.test/x.zip', false],
            'other port' => ['https://bitbucket.test:8443/x.zip', false],
            'user info' => ['https://user:pw@bitbucket.test/x.zip', false],
            'suffix host' => ['https://bitbucket.test.evil.example/x.zip', false],
            'relative' => ['/x.zip', false],
            'internal address' => ['https://169.254.169.254/latest', false],
        ];
    }

    #[DataProvider('origins')]
    public function test_only_exact_origins_are_allowed(string $url, bool $allowed): void
    {
        $e = $this->error(fn () => $this->http()->assertOrigin($url, [FakeProviders::BITBUCKET_WEB]));
        $this->assertSame($allowed, $e === null);
        $this->assertSame($allowed ? null : ProviderError::RedirectRejected, $e?->error);
    }

    public function test_status_codes_map_to_safe_errors_and_only_reads_are_retried(): void
    {
        foreach ([401 => ProviderError::Unauthorized, 403 => ProviderError::Forbidden, 404 => ProviderError::NotFound, 500 => ProviderError::Unavailable, 418 => ProviderError::InvalidResponse, 302 => ProviderError::RedirectRejected] as $status => $error) {
            $this->providers->override = fn () => Http::response('', $status, ['Location' => 'https://elsewhere.example/']);
            $this->assertSame($error, $this->error(fn () => $this->http()->getJson('/2.0/user', 't'))?->error, (string) $status);
        }

        $this->providers->override = fn () => Http::response([], 429, ['Retry-After' => '99999']);
        $e = $this->error(fn () => $this->http()->getJson('/2.0/user', 't'));
        $this->assertSame([ProviderError::RateLimited, 3600], [$e?->error, $e?->retryAfterSeconds]);

        $this->providers->requests = [];
        $this->providers->override = fn () => Http::response('', 503);
        $this->error(fn () => $this->http()->getJson('/2.0/user', 't'));
        $this->assertCount(2, $this->providers->requests, 'a GET is retried once');
        $this->providers->requests = [];
        $this->error(fn () => $this->http()->postForm('/site/oauth2/access_token', ['grant_type' => 'refresh_token']));
        $this->assertCount(1, $this->providers->requests, 'a POST is never retried');
    }

    public function test_bodies_are_bounded_and_must_be_json(): void
    {
        config(['codedna.github.max_response_bytes' => 1024]);
        $this->providers->override = fn () => Http::response(str_repeat(' ', 2048).'{}', 200, ['Content-Type' => 'application/json', 'Content-Length' => '0']);
        $this->assertSame(ProviderError::TooLarge, $this->error(fn () => $this->http()->getJson('/2.0/user', 't'))?->error, 'counted while reading, whatever the header says');
        $this->providers->override = fn () => Http::response('<html>', 200);
        $this->assertSame(ProviderError::InvalidResponse, $this->error(fn () => $this->http()->getJson('/2.0/user', 't'))?->error);
    }

    public function test_requests_and_downloads_never_leave_the_providers_origins(): void
    {
        $this->assertSame(ProviderError::RedirectRejected, $this->error(fn () => $this->http()->download('https://evil.example/x.zip', 't', tempnam(sys_get_temp_dir(), 'p'), 1024))?->error);
        $this->assertSame([], $this->providers->requestsTo('evil.example'));
        // A redirect is followed once at most: a second one is refused.
        config(['codedna.repository_providers.bitbucket.archive_origins' => [FakeProviders::BITBUCKET_WEB]]);
        $this->providers->override = fn (Request $r) => Http::response('', 302, ['Location' => FakeProviders::BITBUCKET_WEB.'/again.zip']);
        $this->assertSame(ProviderError::RedirectRejected, $this->error(fn () => $this->http()->download(FakeProviders::BITBUCKET_WEB.'/a.zip', 't', tempnam(sys_get_temp_dir(), 'p'), 1024))?->error);
        $this->assertCount(2, $this->providers->requests);
    }

    public function test_a_download_is_cut_off_at_the_limit_whatever_content_length_claims(): void
    {
        $this->providers->override = fn () => Http::response(str_repeat('A', 300000), 200, ['Content-Type' => 'application/zip', 'Content-Length' => '10']);
        $destination = tempnam(sys_get_temp_dir(), 'p');
        try {
            $this->assertSame(ProviderError::TooLarge, $this->error(fn () => $this->http()->download(FakeProviders::BITBUCKET_WEB.'/a.zip', 't', $destination, 100000))?->error);
            $this->assertLessThanOrEqual(100000 + 65536, filesize($destination), 'it stops reading at the limit');
        } finally {
            @unlink($destination);
        }
    }

    public function test_token_responses_are_validated_and_never_printed(): void
    {
        foreach ([null, [], ['access_token' => ''], ['access_token' => 'x', 'token_type' => 'mac'], ['access_token' => str_repeat('x', 4097)]] as $bad) {
            $this->assertSame(ProviderError::InvalidResponse, $this->error(fn () => ProviderTokens::fromApi($bad))?->error);
        }
        $tokens = ProviderTokens::fromApi(['access_token' => 'secret-a', 'refresh_token' => 'secret-r', 'expires_in' => 60, 'token_type' => 'Bearer']);
        $printed = print_r($tokens, true).var_export(json_encode($tokens->__debugInfo()), true);
        $this->assertStringNotContainsString('secret-a', $printed);
        $this->assertStringNotContainsString('secret-r', $printed);
    }

    /** @return iterable<string, array{RepositoryProviderKey, list<string>, list<string>}> */
    public static function repositoryIds(): iterable
    {
        yield 'GitLab' => [RepositoryProviderKey::GitLab, ['1', '4242'], ['0', '-1', '01', 'abc', '1/2', str_repeat('9', 20), '']];
        yield 'Bitbucket Cloud' => [RepositoryProviderKey::Bitbucket, [FakeProviders::BB_WORKSPACE.'/'.FakeProviders::BB_REPOSITORY], [
            FakeProviders::BB_REPOSITORY, 'acme/app', '{x}/{y}', FakeProviders::BB_WORKSPACE.'/../'.FakeProviders::BB_REPOSITORY, '',
        ]];
    }

    /**
     * @param  list<string>  $valid
     * @param  list<string>  $invalid
     */
    #[DataProvider('repositoryIds')]
    public function test_repository_ids_have_one_strict_shape(RepositoryProviderKey $key, array $valid, array $invalid): void
    {
        $provider = app(RepositoryProviders::class)->get($key);
        foreach ($valid as $id) {
            $this->assertTrue($provider->isRepositoryId($id), $id);
        }
        foreach ($invalid as $id) {
            $this->assertFalse($provider->isRepositoryId($id), $id);
        }
    }

    /** @return iterable<string, array{RepositoryProviderKey, mixed, ?ProviderError}> */
    public static function malformedRepositories(): iterable
    {
        // Each case is a valid response with exactly one defect.
        $gitlab = FakeProviders::gitlabProject(FakeProviders::GITLAB_PROJECT, 'acme/app', 'private');
        $bitbucket = FakeProviders::bitbucketRepository(FakeProviders::BB_REPOSITORY, 'app', true);
        yield 'GitLab: valid control' => [RepositoryProviderKey::GitLab, $gitlab, null];
        yield 'GitLab: not an object' => [RepositoryProviderKey::GitLab, ['x'], ProviderError::InvalidResponse];
        yield 'GitLab: no id' => [RepositoryProviderKey::GitLab, array_diff_key($gitlab, ['id' => 0]), ProviderError::InvalidResponse];
        yield 'GitLab: parent segment' => [RepositoryProviderKey::GitLab, ['path_with_namespace' => '../../etc'] + $gitlab, ProviderError::InvalidResponse];
        yield 'GitLab: dot segment' => [RepositoryProviderKey::GitLab, ['path_with_namespace' => 'acme/./app'] + $gitlab, ProviderError::InvalidResponse];
        yield 'GitLab: unknown visibility' => [RepositoryProviderKey::GitLab, ['visibility' => 'secret'] + $gitlab, ProviderError::InvalidResponse];
        yield 'Bitbucket: valid control' => [RepositoryProviderKey::Bitbucket, $bitbucket, null];
        yield 'Bitbucket: not an object' => [RepositoryProviderKey::Bitbucket, 'x', ProviderError::InvalidResponse];
        yield 'Bitbucket: bad uuid' => [RepositoryProviderKey::Bitbucket, ['uuid' => 'nope'] + $bitbucket, ProviderError::InvalidResponse];
        yield 'Bitbucket: full name mismatch' => [RepositoryProviderKey::Bitbucket, ['full_name' => 'other/app'] + $bitbucket, ProviderError::InvalidResponse];
    }

    #[DataProvider('malformedRepositories')]
    public function test_malformed_repository_data_is_refused(RepositoryProviderKey $key, mixed $body, ?ProviderError $expected): void
    {
        $this->providers->override = fn () => Http::response($body, 200);
        $provider = app(RepositoryProviders::class)->get($key);
        $id = $key === RepositoryProviderKey::GitLab ? (string) FakeProviders::GITLAB_PROJECT : FakeProviders::BB_WORKSPACE.'/'.FakeProviders::BB_REPOSITORY;
        $this->assertSame($expected, $this->error(fn (): ProviderRepository => $provider->repository(FakeProviders::ACCESS_TOKEN, $id))?->error);
    }
}
