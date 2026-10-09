<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use JsonException;
use Psr\Http\Message\StreamInterface;

/**
 * The only place CodeDNA sends HTTP requests to GitLab or Bitbucket Cloud
 * (Phase 28, docs/integrations/import-security.md#http-client). The same
 * rules as GitHubHttp:
 *
 * - Every URL is the provider's configured API or OAuth origin plus a path
 *   the adapter built from validated, encoded segments; the origin is checked
 *   again here before anything is sent. Nothing comes from a client.
 * - Redirects are never followed automatically. An archive download may be
 *   redirected once, only to a configured archive origin; the Authorization
 *   header is sent only to the provider's own origin.
 * - Explicit connect, request and download timeouts.
 * - Bodies are read as streams and refused beyond their limit (JSON:
 *   max_response_bytes; archives: the upload archive limit), so a hostile
 *   or broken response cannot exhaust memory or disk.
 * - One retry of an idempotent GET after a connection failure or a
 *   502/503/504; never for POST, 4xx or rate limits.
 * - 429 becomes RateLimited with the provider's delay (clamped to 1–3600 s).
 *
 * Failures are ProviderExceptions without bodies, URLs, headers or tokens.
 */
final readonly class ProviderHttp
{
    private const USER_AGENT = 'CodeDNA';

    private const TRANSIENT = [502, 503, 504];

    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(private Http $http, private ProviderSettings $settings) {}

    /**
     * GET {api_url}{path} with the user's token.
     *
     * @param  array<string, scalar>  $query
     * @return array{json: mixed, headers: array<string, string>}
     *
     * @throws ProviderException
     */
    public function getJson(string $path, #[\SensitiveParameter] string $token, array $query = []): array
    {
        $url = $this->settings->apiUrl.$path;
        $this->assertOrigin($url, [ProviderSettings::origin($this->settings->apiUrl)]);
        $response = $this->send(fn (): Response => $this->base()->withToken($token)->acceptJson()->get($url, $query), retry: true);

        return [
            'json' => $this->decode($this->body($response)),
            'headers' => array_filter([
                'x-next-page' => $response->header('X-Next-Page'),
                'link' => $response->header('Link'),
            ], static fn (string $value): bool => $value !== ''),
        ];
    }

    /**
     * POST a form to {web_url}{path}: the OAuth token and revocation endpoints.
     * With $basicAuth the client credentials go in the Authorization header
     * (Bitbucket); otherwise the caller puts them in the form (GitLab). Never retried.
     *
     * @param  array<string, string>  $form
     *
     * @throws ProviderException
     */
    public function postForm(string $path, #[\SensitiveParameter] array $form, bool $basicAuth = false, bool $expectJson = true): mixed
    {
        $url = $this->settings->webUrl.$path;
        $this->assertOrigin($url, [ProviderSettings::origin($this->settings->webUrl)]);
        $response = $this->send(function () use ($url, $form, $basicAuth): Response {
            $request = $this->base()->acceptJson()->asForm();
            if ($basicAuth) {
                $request = $request->withBasicAuth($this->settings->clientId, $this->settings->clientSecret);
            }

            return $request->post($url, $form);
        }, retry: false);

        return $expectJson ? $this->decode($this->body($response)) : null;
    }

    /**
     * Streams an archive into $destination, refusing more than $maxBytes.
     * $url must be on the provider's API or web origin (built by the adapter).
     * A single redirect is followed only to a configured archive origin, and
     * the token is sent only to the provider's own origin.
     *
     * @throws ProviderException
     */
    public function download(string $url, #[\SensitiveParameter] string $token, string $destination, int $maxBytes): void
    {
        $own = [ProviderSettings::origin($this->settings->apiUrl), ProviderSettings::origin($this->settings->webUrl)];
        $this->assertOrigin($url, $own);
        $response = $this->send(fn (): Response => $this->archive($token)->get($url), retry: true, allowRedirect: true);
        if (in_array($response->status(), self::REDIRECTS, true)) {
            $location = $response->header('Location');
            $this->assertOrigin($location, $this->settings->archiveOrigins);
            $sameOrigin = in_array(ProviderSettings::origin($location), $own, true);
            $response = $this->send(fn (): Response => $this->archive($sameOrigin ? $token : null)->get($location), retry: true);
        }

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! in_array($type, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            throw new ProviderException(ProviderError::InvalidResponse, $response->status());
        }
        $declared = $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            throw new ProviderException(ProviderError::TooLarge, $response->status());
        }
        $out = fopen($destination, 'wb');
        if ($out === false) {
            throw new ProviderException(ProviderError::Unavailable);
        }
        try {
            $this->copy($response->toPsrResponse()->getBody(), $out, $maxBytes);
        } finally {
            fclose($out);
        }
    }

    /**
     * Only an exact allowed origin (scheme, host and port), without user info.
     *
     * @param  list<string>  $origins
     *
     * @throws ProviderException
     */
    public function assertOrigin(string $url, array $origins): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ! in_array(ProviderSettings::origin($url), array_map('strtolower', $origins), true)) {
            throw new ProviderException(ProviderError::RedirectRejected);
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

    private function archive(#[\SensitiveParameter] ?string $token): PendingRequest
    {
        $request = $this->base()->timeout($this->settings->downloadTimeoutSeconds)->withHeaders(['Accept' => 'application/zip']);

        return $token === null ? $request : $request->withToken($token);
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws ProviderException
     */
    private function send(callable $request, bool $retry, bool $allowRedirect = false): Response
    {
        $attempts = $retry ? 2 : 1;
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $request();
            } catch (ConnectionException $e) {
                $error = str_contains($e->getMessage(), 'cURL error 28') ? ProviderError::Timeout : ProviderError::Unavailable;
                if ($attempt < $attempts) {
                    $this->pause();

                    continue;
                }
                throw new ProviderException($error);
            }

            $status = $response->status();
            if (in_array($status, self::TRANSIENT, true) && $attempt < $attempts) {
                $this->pause();

                continue;
            }
            if (($status >= 200 && $status < 300) || ($allowRedirect && in_array($status, self::REDIRECTS, true))) {
                return $response;
            }

            throw $this->failure($response);
        }
    }

    private function failure(Response $response): ProviderException
    {
        $status = $response->status();
        if ($status === 429) {
            return new ProviderException(ProviderError::RateLimited, $status, $this->retryAfter($response));
        }

        return new ProviderException(match (true) {
            $status === 401 => ProviderError::Unauthorized,
            $status === 403 => ProviderError::Forbidden,
            $status === 404 => ProviderError::NotFound,
            in_array($status, self::REDIRECTS, true) => ProviderError::RedirectRejected,
            $status >= 500 => ProviderError::Unavailable,
            default => ProviderError::InvalidResponse,
        }, $status);
    }

    /** Seconds until the provider accepts requests again, clamped to [1, 3600]. */
    private function retryAfter(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');
        $reset = $response->header('RateLimit-Reset');
        $seconds = match (true) {
            ctype_digit($retryAfter) => (int) $retryAfter,
            ctype_digit($reset) => (int) $reset - Carbon::now()->getTimestamp(),
            default => 60,
        };

        return max(1, min(3600, $seconds));
    }

    private function body(Response $response): string
    {
        $limit = $this->settings->maxResponseBytes;
        $declared = $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $limit) {
            throw new ProviderException(ProviderError::TooLarge, $response->status());
        }
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(65536);
            if (strlen($body) > $limit) {
                throw new ProviderException(ProviderError::TooLarge, $response->status());
            }
        }

        return $body;
    }

    private function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }
    }

    /**
     * @param  resource  $out
     *
     * @throws ProviderException
     */
    private function copy(StreamInterface $in, $out, int $maxBytes): void
    {
        $written = 0;
        while (! $in->eof()) {
            $chunk = $in->read(65536);
            $written += strlen($chunk);
            if ($written > $maxBytes) {
                throw new ProviderException(ProviderError::TooLarge);
            }
            if (fwrite($out, $chunk) === false) {
                throw new ProviderException(ProviderError::Unavailable);
            }
        }
    }

    private function pause(): void
    {
        if ($this->settings->retryDelayMs > 0) {
            usleep($this->settings->retryDelayMs * 1000);
        }
    }
}
