<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use JsonException;
use Psr\Http\Message\StreamInterface;

/**
 * The only place CodeDNA sends HTTP requests to GitHub
 * (docs/architecture/github-integration-v1.md#github-client).
 *
 * - Every URL is the configured API or web origin plus a path built by
 *   GitHubApi from validated, encoded segments; nothing comes from a request.
 * - Explicit connect and total timeouts; redirects are never followed here.
 * - Response bodies are read as a stream and refused beyond
 *   max_response_bytes, so a hostile or broken response cannot exhaust memory.
 * - One retry of an idempotent GET after a connection failure or a
 *   502/503/504; never after 4xx, rate limiting or for POST.
 * - Rate limits (429, or 403 with no remaining quota) become RateLimited
 *   with the Retry-After / reset delay; nothing is retried blindly.
 *
 * Failures are GitHubExceptions without bodies, URLs, headers or tokens.
 */
final readonly class GitHubHttp
{
    public const API_VERSION = '2022-11-28';

    private const USER_AGENT = 'CodeDNA';

    private const TRANSIENT = [502, 503, 504];

    public function __construct(private Http $http, private GitHubSettings $settings) {}

    /**
     * GET {api_url}{path}. Returns the decoded JSON and whether a next page exists (Link: rel="next").
     *
     * @param  array<string, scalar>  $query
     * @return array{json: mixed, next: bool}
     *
     * @throws GitHubException
     */
    public function getJson(string $path, string $token, array $query = []): array
    {
        $response = $this->send(fn (): Response => $this->api($token)->get($this->settings->apiUrl.$path, $query), retry: true);

        return ['json' => $this->decode($this->body($response)), 'next' => self::hasNextPage($response)];
    }

    /**
     * POST {api_url}{path} with a JSON body. Never retried.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws GitHubException
     */
    public function postJson(string $path, string $token, array $body): mixed
    {
        $response = $this->send(fn (): Response => $this->api($token)->post($this->settings->apiUrl.$path, $body), retry: false);

        return $this->decode($this->body($response));
    }

    /**
     * POST {web_url}{path} as a form, for the OAuth token endpoint. Never retried.
     *
     * @param  array<string, string>  $form
     *
     * @throws GitHubException
     */
    public function postWebForm(string $path, array $form): mixed
    {
        $response = $this->send(fn (): Response => $this->base()
            ->withHeaders(['Accept' => 'application/json'])
            ->asForm()
            ->post($this->settings->webUrl.$path, $form), retry: false);

        return $this->decode($this->body($response));
    }

    /**
     * GET {api_url}{path} expecting a redirect: returns the Location header, unread.
     *
     * @throws GitHubException
     */
    public function redirectLocation(string $path, string $token): string
    {
        $response = $this->send(fn (): Response => $this->api($token)->get($this->settings->apiUrl.$path), retry: true, expectRedirect: true);
        $location = $response->header('Location');
        if ($location === '') {
            throw new GitHubException(GitHubError::InvalidResponse, $response->status());
        }

        return $location;
    }

    /**
     * Streams an archive from an allowed origin into $destination, refusing
     * more than $maxBytes. No credential is sent: the URL GitHub redirected
     * to is itself the short-lived authorization.
     *
     * @throws GitHubException
     */
    public function download(string $url, string $destination, int $maxBytes): void
    {
        $this->assertAllowedOrigin($url);

        $response = $this->send(fn (): Response => $this->base()
            ->timeout($this->settings->downloadTimeoutSeconds)
            ->withHeaders(['Accept' => 'application/zip'])
            ->get($url), retry: true);

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! in_array($type, ['application/zip', 'application/x-zip-compressed'], true)) {
            throw new GitHubException(GitHubError::InvalidResponse, $response->status());
        }
        $declared = $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            throw new GitHubException(GitHubError::TooLarge, $response->status());
        }

        $out = fopen($destination, 'wb');
        if ($out === false) {
            throw new GitHubException(GitHubError::Unavailable);
        }
        try {
            $this->copy($response->toPsrResponse()->getBody(), $out, $maxBytes);
        } finally {
            fclose($out);
        }
    }

    /**
     * Only an exact allowed origin (scheme, host and port) is ever fetched.
     *
     * @throws GitHubException
     */
    public function assertAllowedOrigin(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new GitHubException(GitHubError::RedirectRejected);
        }
        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (! in_array($origin, array_map('strtolower', $this->settings->archiveOrigins), true)) {
            throw new GitHubException(GitHubError::RedirectRejected);
        }
    }

    private function base(): PendingRequest
    {
        return $this->http
            ->withUserAgent(self::USER_AGENT)
            ->withOptions(['stream' => true])
            ->connectTimeout($this->settings->connectTimeoutSeconds)
            ->timeout($this->settings->timeoutSeconds)
            ->withoutRedirecting();
    }

    private function api(string $token): PendingRequest
    {
        return $this->base()->withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
            'Authorization' => 'Bearer '.$token,
        ]);
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws GitHubException
     */
    private function send(callable $request, bool $retry, bool $expectRedirect = false): Response
    {
        $attempts = $retry ? 2 : 1;
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $request();
            } catch (ConnectionException $e) {
                $error = str_contains($e->getMessage(), 'cURL error 28') ? GitHubError::Timeout : GitHubError::Unavailable;
                if ($attempt < $attempts) {
                    $this->pause();

                    continue;
                }
                throw new GitHubException($error);
            }

            $status = $response->status();
            if (in_array($status, self::TRANSIENT, true) && $attempt < $attempts) {
                $this->pause();

                continue;
            }
            if ($expectRedirect ? in_array($status, [301, 302, 303, 307, 308], true) : ($status >= 200 && $status < 300)) {
                return $response;
            }

            throw $this->failure($response);
        }
    }

    private function failure(Response $response): GitHubException
    {
        $status = $response->status();
        $exhausted = $response->header('X-RateLimit-Remaining') === '0';
        if ($status === 429 || ($status === 403 && ($exhausted || $response->header('Retry-After') !== ''))) {
            return new GitHubException(GitHubError::RateLimited, $status, $this->retryAfter($response));
        }

        return new GitHubException(match (true) {
            $status === 401 => GitHubError::Unauthorized,
            $status === 403 => GitHubError::Forbidden,
            $status === 404 => GitHubError::NotFound,
            $status === 422 => GitHubError::Unprocessable,
            $status >= 500 => GitHubError::Unavailable,
            default => GitHubError::InvalidResponse,
        }, $status);
    }

    /** Seconds until GitHub accepts requests again, clamped to [1, 3600]. */
    private function retryAfter(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');
        $reset = $response->header('X-RateLimit-Reset');
        $seconds = match (true) {
            ctype_digit($retryAfter) => (int) $retryAfter,
            ctype_digit($reset) => (int) $reset - Carbon::now()->getTimestamp(),
            default => 60,
        };

        return max(1, min(3600, $seconds));
    }

    private function body(Response $response): string
    {
        $declared = $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $this->settings->maxResponseBytes) {
            throw new GitHubException(GitHubError::TooLarge, $response->status());
        }
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(65536);
            if (strlen($body) > $this->settings->maxResponseBytes) {
                throw new GitHubException(GitHubError::TooLarge, $response->status());
            }
        }

        return $body;
    }

    private function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }
    }

    /**
     * @param  resource  $out
     */
    private function copy(StreamInterface $in, $out, int $maxBytes): void
    {
        $written = 0;
        while (! $in->eof()) {
            $chunk = $in->read(65536);
            $written += strlen($chunk);
            if ($written > $maxBytes) {
                throw new GitHubException(GitHubError::TooLarge);
            }
            if (fwrite($out, $chunk) === false) {
                throw new GitHubException(GitHubError::Unavailable);
            }
        }
    }

    private static function hasNextPage(Response $response): bool
    {
        return (bool) preg_match('/<[^>]*>;\s*rel="next"/', $response->header('Link'));
    }

    private function pause(): void
    {
        if ($this->settings->retryDelayMs > 0) {
            usleep($this->settings->retryDelayMs * 1000);
        }
    }
}
