<?php

declare(strict_types=1);

namespace App\Services\Assessment\Provider;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use JsonException;

/**
 * Any endpoint that implements the OpenAI Chat Completions API
 * (POST {base_url}/chat/completions): hosted services and local servers
 * such as vLLM, llama.cpp or Ollama (docs/architecture/ai-assessment-v1.md#providers).
 *
 * - The base URL, model and key come only from configuration. The key is
 *   sent as a bearer token when set and is never logged or stored.
 * - temperature 0 and a bounded max_tokens; structured output with the
 *   assessment schema ("json_schema", strict) where supported, or plain
 *   JSON mode / none for servers without it: the local validator enforces
 *   the contract either way.
 * - No redirects; connect and total timeouts.
 *
 * Failures are normalized: timeouts, transport errors, 429 and 5xx are
 * retryable; 401/403, other 4xx, refusals, truncated or oversized output
 * and malformed envelopes are not.
 */
final class OpenAiCompatibleProvider implements AiProvider
{
    public const NAME = 'openai_compatible';

    public const STRUCTURED_OUTPUT_MODES = ['json_schema', 'json_object', 'none'];

    /**
     * @param  array<string, mixed>  $config  config('codedna.ai')
     */
    public function __construct(private readonly Http $http, private readonly array $config) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function generateAssessment(AssessmentInput $input, AssessmentPrompt $prompt): AiProviderResponse
    {
        $request = $this->http
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) $this->config['connect_timeout_seconds'])
            ->timeout((int) $this->config['timeout_seconds'])
            ->withoutRedirecting();
        $key = (string) ($this->config['api_key'] ?? '');
        if ($key !== '') {
            $request = $request->withToken($key);
        }

        try {
            $response = $request->post(rtrim((string) $this->config['base_url'], '/').'/chat/completions', $this->body($prompt));
        } catch (ConnectionException $e) {
            // cURL error 28: the connect or total timeout fired.
            if (str_contains($e->getMessage(), 'cURL error 28')) {
                throw AiProviderException::retryable(AssessmentFailure::ProviderTimeout, 'transport_timeout');
            }
            throw AiProviderException::retryable(AssessmentFailure::ProviderUnavailable, 'transport_error');
        }

        return $this->parse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(AssessmentPrompt $prompt): array
    {
        $body = [
            'model' => $this->model(),
            'temperature' => 0,
            'max_tokens' => (int) $this->config['max_output_tokens'],
            'messages' => [
                ['role' => 'system', 'content' => $prompt->system],
                ['role' => 'user', 'content' => $prompt->user],
            ],
        ];

        return match ($this->config['structured_output'] ?? 'json_schema') {
            'json_schema' => $body + ['response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $prompt->schemaName, 'strict' => true, 'schema' => $prompt->schema],
            ]],
            'json_object' => $body + ['response_format' => ['type' => 'json_object']],
            default => $body,
        };
    }

    private function parse(Response $response): AiProviderResponse
    {
        $status = $response->status();
        if ($status !== 200) {
            throw match (true) {
                $status === 429 => AiProviderException::retryable(AssessmentFailure::ProviderRateLimited, 'rate_limited', $status, $this->retryAfter($response)),
                $status === 408 || $status >= 500 => AiProviderException::retryable(AssessmentFailure::ProviderUnavailable, 'server_error', $status, $this->retryAfter($response)),
                $status === 401 || $status === 403 => AiProviderException::permanent(AssessmentFailure::ProviderAuthFailed, 'auth_rejected', $status),
                default => AiProviderException::permanent(AssessmentFailure::ProviderRejected, 'request_rejected', $status),
            };
        }

        $raw = $response->body();
        // The envelope may add some overhead to the content limit, no more.
        if (strlen($raw) > (int) $this->config['max_output_bytes'] + 16384) {
            throw AiProviderException::permanent(AssessmentFailure::OutputTooLarge, 'envelope_too_large', $status);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AiProviderException::permanent(AssessmentFailure::InvalidOutput, 'envelope_not_json', $status);
        }

        $choice = is_array($decoded) && is_array($decoded['choices'][0] ?? null) ? $decoded['choices'][0] : null;
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : null;
        if ($message === null) {
            throw AiProviderException::permanent(AssessmentFailure::InvalidOutput, 'envelope_invalid', $status);
        }
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw AiProviderException::permanent(AssessmentFailure::OutputTooLarge, 'truncated', $status);
        }
        if (isset($message['refusal']) && $message['refusal'] !== '') {
            throw AiProviderException::permanent(AssessmentFailure::ProviderRejected, 'refusal', $status);
        }
        if (! is_string($message['content'] ?? null) || $message['content'] === '') {
            throw AiProviderException::permanent(AssessmentFailure::InvalidOutput, 'content_missing', $status);
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $count = fn (mixed $v): ?int => is_int($v) && $v >= 0 ? $v : null;

        return new AiProviderResponse(
            content: $message['content'],
            servedModel: is_string($decoded['model'] ?? null) ? $decoded['model'] : null,
            responseId: is_string($decoded['id'] ?? null) ? $decoded['id'] : null,
            inputTokens: $count($usage['prompt_tokens'] ?? null),
            outputTokens: $count($usage['completion_tokens'] ?? null),
        );
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return preg_match('/^\d{1,4}$/', $value) === 1 ? (int) $value : null;
    }
}
