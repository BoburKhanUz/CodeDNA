<?php

declare(strict_types=1);

namespace App\Services\Assessment\Provider;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Ai\AiRequest;
use App\Services\Ai\HttpModelTransport;
use App\Services\Ai\ModelClient;
use App\Services\Ai\ModelHealth;
use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentPrompt;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;
use Throwable;

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
 *
 * Since Phase 29 it is also a ModelClient behind the AI gateway; a local
 * Ollama server is better served by OllamaClient (native API).
 */
final class OpenAiCompatibleProvider implements AiProvider, ModelClient
{
    public const NAME = 'openai_compatible';

    public const STRUCTURED_OUTPUT_MODES = ['json_schema', 'json_object', 'none'];

    private readonly HttpModelTransport $transport;

    /**
     * @param  array<string, mixed>  $config  config('codedna.ai')
     */
    public function __construct(Http $http, private readonly array $config)
    {
        $this->transport = new HttpModelTransport($http, $config);
    }

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
        return $this->complete(AiRequest::fromAssessmentPrompt($prompt, (int) $this->config['max_output_tokens']));
    }

    public function complete(AiRequest $request): AiProviderResponse
    {
        $response = $this->transport->send(fn (PendingRequest $http): Response => $http->post($this->transport->url('/chat/completions'), $this->body($request)));

        return $this->parse($response);
    }

    /**
     * GET {base_url}/models: reachable, and whether the configured model is
     * listed (servers without the endpoint report the model as unknown).
     */
    public function health(): ModelHealth
    {
        $timeout = max(1, min(10, (int) $this->config['connect_timeout_seconds'] * 2));
        try {
            $response = $this->transport->send(fn (PendingRequest $http): Response => $http->get($this->transport->url('/models')), $timeout);
            if ($response->status() === 404) {
                return new ModelHealth(true, null, null, 'models_endpoint_missing');
            }
            if ($response->status() !== 200) {
                return new ModelHealth(false, null, null, 'status_'.$response->status());
            }
            $data = json_decode($this->transport->body($response, 1048576), true, 8, JSON_THROW_ON_ERROR)['data'] ?? [];
        } catch (AiProviderException $e) {
            return new ModelHealth(false, null, null, $e->detail);
        } catch (Throwable) {
            return new ModelHealth(false, null, null, 'invalid_response');
        }
        $ids = array_filter(array_map(fn (mixed $m): ?string => is_array($m) && is_string($m['id'] ?? null) ? $m['id'] : null, is_array($data) ? $data : []));
        $available = in_array($this->model(), $ids, true);

        return new ModelHealth(true, $available, null, $available ? null : 'model_not_found');
    }

    /**
     * @return array<string, mixed>
     */
    private function body(AiRequest $request): array
    {
        $body = [
            'model' => $this->model(),
            'temperature' => 0,
            'max_tokens' => $request->maxOutputTokens,
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->user],
            ],
        ];

        return match ($this->config['structured_output'] ?? 'json_schema') {
            'json_schema' => $body + ['response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $request->schemaName, 'strict' => true, 'schema' => $request->schema],
            ]],
            'json_object' => $body + ['response_format' => ['type' => 'json_object']],
            default => $body,
        };
    }

    private function parse(Response $response): AiProviderResponse
    {
        $status = $response->status();
        if ($status !== 200) {
            throw $this->transport->failure($response);
        }

        $raw = $this->transport->body($response, $this->transport->envelopeLimit());
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
}
