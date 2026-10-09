<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Assessment\Provider\AiProviderResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;
use Throwable;

/**
 * A local Ollama server through its native API (Phase 29,
 * docs/operations/local-ai.md): POST {base_url}/api/chat, non-streaming.
 *
 * - The answer must follow the JSON schema, sent as Ollama's "format"
 *   (structured outputs); AI_STRUCTURED_OUTPUT=json_object sends "json",
 *   "none" sends nothing. The local validators enforce the contract anyway.
 * - Deterministic sampling: temperature 0 and a fixed seed; num_predict is
 *   the output bound and num_ctx the configured context window.
 * - The model is never pulled from here: an unknown model is a permanent
 *   failure (PROVIDER_REJECTED, model_not_found), never a download.
 * - Token counts come from prompt_eval_count / eval_count when Ollama
 *   reports them; otherwise they are unknown (null), never guessed.
 */
final readonly class OllamaClient implements ModelClient
{
    public const NAME = 'ollama';

    /**
     * @param  array<string, mixed>  $config  config('codedna.ai')
     */
    public function __construct(private HttpModelTransport $transport, private array $config) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function complete(AiRequest $request): AiProviderResponse
    {
        $response = $this->transport->send(fn (PendingRequest $http): Response => $http->post($this->transport->url('/api/chat'), $this->body($request)));
        if ($response->status() !== 200) {
            throw $this->transport->failure($response, 'model_not_found');
        }

        $raw = $this->transport->body($response, $this->transport->envelopeLimit());
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AiProviderException::permanent(AssessmentFailure::InvalidOutput, 'envelope_not_json', 200);
        }
        if (! is_array($decoded) || ! is_array($decoded['message'] ?? null)) {
            throw AiProviderException::permanent(AssessmentFailure::InvalidOutput, 'envelope_invalid', 200);
        }
        if (is_string($decoded['error'] ?? null)) {
            throw AiProviderException::permanent(AssessmentFailure::ProviderRejected, 'runtime_error', 200);
        }
        if (($decoded['done_reason'] ?? null) === 'length') {
            throw AiProviderException::permanent(AssessmentFailure::OutputTooLarge, 'truncated', 200);
        }
        if (($decoded['done'] ?? null) !== true) {
            throw AiProviderException::permanent(AssessmentFailure::InvalidOutput, 'incomplete', 200);
        }
        $content = $decoded['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw AiProviderException::permanent(AssessmentFailure::InvalidOutput, 'content_missing', 200);
        }
        $count = fn (mixed $v): ?int => is_int($v) && $v >= 0 ? $v : null;

        return new AiProviderResponse(
            content: $content,
            servedModel: is_string($decoded['model'] ?? null) ? $decoded['model'] : null,
            inputTokens: $count($decoded['prompt_eval_count'] ?? null),
            outputTokens: $count($decoded['eval_count'] ?? null),
        );
    }

    public function health(): ModelHealth
    {
        $timeout = max(1, min(10, (int) $this->config['connect_timeout_seconds'] * 2));
        try {
            $version = $this->transport->send(fn (PendingRequest $http): Response => $http->get($this->transport->url('/api/version')), $timeout);
            if ($version->status() !== 200) {
                return new ModelHealth(false, null, null, 'status_'.$version->status());
            }
            $runtime = json_decode($this->transport->body($version, 4096), true, 4, JSON_THROW_ON_ERROR)['version'] ?? null;
            $tags = $this->transport->send(fn (PendingRequest $http): Response => $http->get($this->transport->url('/api/tags')), $timeout);
            if ($tags->status() !== 200) {
                return new ModelHealth(true, null, self::safeVersion($runtime), 'tags_status_'.$tags->status());
            }
            $models = json_decode($this->transport->body($tags, 1048576), true, 8, JSON_THROW_ON_ERROR)['models'] ?? [];
        } catch (AiProviderException $e) {
            return new ModelHealth(false, null, null, $e->detail);
        } catch (Throwable) {
            return new ModelHealth(false, null, null, 'invalid_response');
        }
        $wanted = $this->model();
        $names = [];
        foreach (is_array($models) ? $models : [] as $model) {
            foreach (['name', 'model'] as $field) {
                if (is_array($model) && is_string($model[$field] ?? null)) {
                    $names[] = $model[$field];
                }
            }
        }
        $available = in_array($wanted, $names, true) || (! str_contains($wanted, ':') && in_array($wanted.':latest', $names, true));

        return new ModelHealth(true, $available, self::safeVersion($runtime), $available ? null : 'model_not_found');
    }

    /**
     * @return array<string, mixed>
     */
    private function body(AiRequest $request): array
    {
        $body = [
            'model' => $this->model(),
            'stream' => false,
            'keep_alive' => (string) $this->config['keep_alive'],
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->user],
            ],
            'options' => [
                'temperature' => 0,
                'seed' => 0,
                'num_predict' => $request->maxOutputTokens,
                'num_ctx' => (int) $this->config['context_tokens'],
            ],
        ];

        return match ($this->config['structured_output'] ?? 'json_schema') {
            'json_schema' => $body + ['format' => $request->schema],
            'json_object' => $body + ['format' => 'json'],
            default => $body,
        };
    }

    private static function safeVersion(mixed $version): ?string
    {
        return is_string($version) && preg_match('/^[0-9A-Za-z.+-]{1,32}$/', $version) === 1 ? $version : null;
    }
}
