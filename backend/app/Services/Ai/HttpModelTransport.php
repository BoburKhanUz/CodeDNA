<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Assessment\Provider\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * What every HTTP model client shares (Phase 21/29): no redirects, connect
 * and total timeouts, a bearer key only when configured, bodies read as a
 * stream and refused past a limit, and transport failures normalized to
 * retryable AiProviderExceptions. Never logs or stores a body or header.
 */
final readonly class HttpModelTransport
{
    /**
     * @param  array<string, mixed>  $config  config('codedna.ai')
     */
    public function __construct(private Http $http, private array $config) {}

    public function request(?int $timeoutSeconds = null): PendingRequest
    {
        $timeout = $timeoutSeconds ?? (int) $this->config['timeout_seconds'];
        $request = $this->http
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) $this->config['connect_timeout_seconds'])
            ->timeout($timeout)
            ->withoutRedirecting()
            // A streamed response is read through PHP streams, whose read timeout is
            // otherwise default_socket_timeout (60 s) whatever AI_TIMEOUT_SECONDS says:
            // slow local inference must be bounded by the configured timeout instead.
            ->withOptions(['stream' => true, 'read_timeout' => $timeout]);
        $key = (string) ($this->config['api_key'] ?? '');

        return $key === '' ? $request : $request->withToken($key);
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws AiProviderException
     */
    public function send(callable $send, ?int $timeoutSeconds = null): Response
    {
        try {
            return $send($this->request($timeoutSeconds));
        } catch (ConnectionException $e) {
            // cURL error 28: the connect or total timeout fired.
            if (str_contains($e->getMessage(), 'cURL error 28')) {
                throw AiProviderException::retryable(AssessmentFailure::ProviderTimeout, 'transport_timeout');
            }
            throw AiProviderException::retryable(AssessmentFailure::ProviderUnavailable, 'transport_error');
        }
    }

    /**
     * Non-200 statuses, normalized: 429, 408 and 5xx may succeed later; the
     * rest never will. $notFound names a 404 (e.g. an unknown model).
     */
    public function failure(Response $response, string $notFound = 'request_rejected'): AiProviderException
    {
        $status = $response->status();

        return match (true) {
            $status === 429 => AiProviderException::retryable(AssessmentFailure::ProviderRateLimited, 'rate_limited', $status, $this->retryAfter($response)),
            $status === 408 || $status >= 500 => AiProviderException::retryable(AssessmentFailure::ProviderUnavailable, 'server_error', $status, $this->retryAfter($response)),
            $status === 401 || $status === 403 => AiProviderException::permanent(AssessmentFailure::ProviderAuthFailed, 'auth_rejected', $status),
            $status === 404 => AiProviderException::permanent(AssessmentFailure::ProviderRejected, $notFound, $status),
            default => AiProviderException::permanent(AssessmentFailure::ProviderRejected, 'request_rejected', $status),
        };
    }

    /**
     * The body, refused as soon as it exceeds $limit bytes: a misbehaving
     * endpoint cannot make the worker buffer an unbounded response. The
     * declared length is checked first, then the bytes actually read.
     *
     * @throws AiProviderException
     */
    public function body(Response $response, int $limit): string
    {
        $status = $response->status();
        $declared = $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $limit) {
            throw AiProviderException::permanent(AssessmentFailure::OutputTooLarge, 'envelope_too_large', $status);
        }
        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = '';
        while (! $stream->eof()) {
            $raw .= $stream->read(65536);
            if (strlen($raw) > $limit) {
                $stream->close();
                throw AiProviderException::permanent(AssessmentFailure::OutputTooLarge, 'envelope_too_large', $status);
            }
        }

        return $raw;
    }

    /** The response envelope limit: the content limit plus room for the envelope. */
    public function envelopeLimit(): int
    {
        return (int) $this->config['max_output_bytes'] + 16384;
    }

    public function url(string $path): string
    {
        return rtrim((string) $this->config['base_url'], '/').$path;
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return preg_match('/^\d{1,4}$/', $value) === 1 ? (int) $value : null;
    }
}
