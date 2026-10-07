<?php

declare(strict_types=1);

namespace Tests\Unit\GitHub;

use App\Services\GitHub\GitHubError;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGitHub;
use Tests\TestCase;

/**
 * The GitHub HTTP layer: configured origins only, bounded bodies, explicit
 * timeouts, one retry for idempotent GETs, rate limits and safe failures.
 */
final class GitHubHttpTest extends TestCase
{
    private int $calls = 0;

    /** @var list<mixed> */
    private array $answers = [];

    /** @var list<array<string, mixed>> Guzzle options of each request */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakeGitHub::configure();
        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) {
            $this->options[] = $options;
            $answer = $this->answers[min($this->calls, count($this->answers) - 1)];
            $this->calls++;
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });
    }

    private function http(): GitHubHttp
    {
        return app(GitHubHttp::class);
    }

    /**
     * @param  callable(): mixed  $call
     */
    private function failure(callable $call): GitHubException
    {
        try {
            $call();
        } catch (GitHubException $e) {
            return $e;
        }
        $this->fail('Expected a GitHubException.');
    }

    /**
     * @param  list<mixed>  $answers  responses or exceptions, in order
     */
    private function answer(array $answers): void
    {
        $this->answers = $answers;
        $this->calls = 0;
    }

    public function test_a_get_is_sent_to_the_configured_api_with_versioned_headers_and_bounded_timeouts(): void
    {
        $this->answer([Http::response(['ok' => true], 200, ['Link' => '<https://api.github.test/x?page=2>; rel="next", <https://api.github.test/x?page=9>; rel="last"'])]);

        $result = $this->http()->getJson('/user', 'tkn', ['per_page' => 5]);

        $this->assertSame(['json' => ['ok' => true], 'next' => true], $result);
        $this->assertSame([5, 15, false, true], [$this->options[0]['connect_timeout'], $this->options[0]['timeout'], $this->options[0]['allow_redirects'], $this->options[0]['stream']]);
        Http::assertSent(function (Request $r): bool {
            return $r->url() === FakeGitHub::API.'/user?per_page=5'
                && $r->header('Authorization')[0] === 'Bearer tkn'
                && $r->header('X-GitHub-Api-Version')[0] === '2022-11-28'
                && $r->header('Accept')[0] === 'application/vnd.github+json';
        });
    }

    public function test_the_last_page_has_no_next(): void
    {
        $this->answer([Http::response([], 200, ['Link' => '<https://api.github.test/x?page=1>; rel="prev"'])]);

        $this->assertFalse($this->http()->getJson('/x', 't')['next']);
    }

    public function test_a_timeout_is_retried_once_then_reported(): void
    {
        $this->answer([new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds')]);

        $e = $this->failure(fn () => $this->http()->getJson('/user', 't'));

        $this->assertSame(GitHubError::Timeout, $e->error);
        $this->assertSame(2, $this->calls);
    }

    public function test_a_transient_status_is_retried_once(): void
    {
        $this->answer([Http::response('', 502), Http::response(['id' => 1])]);
        $this->assertSame(['id' => 1], $this->http()->getJson('/user', 't')['json']);
        $this->assertSame(2, $this->calls);

        $this->calls = 0;
        $this->answer([Http::response('', 503)]);
        $this->assertSame(GitHubError::Unavailable, $this->failure(fn () => $this->http()->getJson('/user', 't'))->error);
        $this->assertSame(2, $this->calls, 'never more than one retry');
    }

    public function test_posts_and_client_errors_are_never_retried(): void
    {
        $this->answer([Http::response('', 502)]);
        $this->failure(fn () => $this->http()->postJson('/app/installations/1/access_tokens', 't', []));
        $this->assertSame(1, $this->calls);

        foreach ([401 => GitHubError::Unauthorized, 403 => GitHubError::Forbidden, 404 => GitHubError::NotFound, 422 => GitHubError::Unprocessable, 418 => GitHubError::InvalidResponse, 500 => GitHubError::Unavailable] as $status => $error) {
            $this->calls = 0;
            $this->answer([Http::response(['message' => 'secret detail ghu_x'], $status)]);
            $e = $this->failure(fn () => $this->http()->getJson('/user', 't'));
            $this->assertSame([$error, $status], [$e->error, $e->status]);
            $this->assertSame(1, $this->calls, 'only 502/503/504 are retried');
            $this->assertStringNotContainsString('secret detail', $e->getMessage());
            $this->assertStringNotContainsString('ghu_x', $e->getMessage());
        }
    }

    public function test_rate_limits_are_reported_with_their_delay_and_not_retried(): void
    {
        $this->answer([Http::response([], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 300)])]);
        $e = $this->failure(fn () => $this->http()->getJson('/user', 't'));
        $this->assertSame(GitHubError::RateLimited, $e->error);
        $this->assertEqualsWithDelta(300, $e->retryAfterSeconds, 2);
        $this->assertSame(1, $this->calls);

        $this->calls = 0;
        $this->answer([Http::response([], 429, ['Retry-After' => '45'])]);
        $this->assertSame(45, $this->failure(fn () => $this->http()->getJson('/user', 't'))->retryAfterSeconds);
        $this->answer([Http::response([], 403, ['Retry-After' => '999999'])]);
        $this->assertSame(3600, $this->failure(fn () => $this->http()->getJson('/user', 't'))->retryAfterSeconds, 'bounded');
        $this->answer([Http::response([], 403, ['X-RateLimit-Remaining' => '12'])]);
        $this->assertSame(GitHubError::Forbidden, $this->failure(fn () => $this->http()->getJson('/user', 't'))->error);
    }

    public function test_bodies_are_bounded(): void
    {
        config(['codedna.github.max_response_bytes' => 1000]);
        $this->answer([Http::response(str_repeat('a', 1001), 200)]);
        $this->assertSame(GitHubError::TooLarge, $this->failure(fn () => $this->http()->getJson('/user', 't'))->error);

        $this->answer([Http::response('[]', 200, ['Content-Length' => '5000000'])]);
        $this->assertSame(GitHubError::TooLarge, $this->failure(fn () => $this->http()->getJson('/user', 't'))->error);
    }

    public function test_malformed_json_is_an_invalid_response(): void
    {
        $this->answer([Http::response('{"id": 1', 200)]);

        $this->assertSame(GitHubError::InvalidResponse, $this->failure(fn () => $this->http()->getJson('/user', 't'))->error);
    }

    public function test_redirects_are_never_followed_for_api_calls(): void
    {
        $this->answer([Http::response('', 302, ['Location' => 'https://evil.test/'])]);

        $this->assertSame(GitHubError::InvalidResponse, $this->failure(fn () => $this->http()->getJson('/user', 't'))->error);
        $this->assertSame(1, $this->calls);
    }

    public function test_downloads_come_only_from_allowed_origins_and_are_capped(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'gh-test-');
        try {
            foreach (['https://evil.test/a.zip', 'http://codeload.github.test/a.zip', 'https://codeload.github.test:444/a', 'https://a@codeload.github.test/a', 'file:///etc/passwd', 'codeload.github.test/a'] as $url) {
                $this->assertSame(GitHubError::RedirectRejected, $this->failure(fn () => $this->http()->download($url, $path, 100))->error, $url);
            }
            $this->assertSame(0, $this->calls);

            $this->answer([Http::response(str_repeat('x', 101), 200, ['Content-Type' => 'application/zip'])]);
            $this->assertSame(GitHubError::TooLarge, $this->failure(fn () => $this->http()->download(FakeGitHub::CODELOAD.'/a.zip', $path, 100))->error);

            $this->answer([Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);
            $this->assertSame(GitHubError::InvalidResponse, $this->failure(fn () => $this->http()->download(FakeGitHub::CODELOAD.'/a.zip', $path, 100))->error);

            $this->answer([Http::response('PK-bytes', 200, ['Content-Type' => 'application/zip; charset=binary'])]);
            $this->options = [];
            $this->http()->download(FakeGitHub::CODELOAD.'/a.zip', $path, 100);
            $this->assertSame('PK-bytes', file_get_contents($path));
            $this->assertSame([120, false], [$this->options[0]['timeout'], $this->options[0]['allow_redirects']]);
        } finally {
            @unlink($path);
        }
    }
}
