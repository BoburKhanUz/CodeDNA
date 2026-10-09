<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Ai\AiRequest;
use App\Services\Ai\HttpModelTransport;
use App\Services\Ai\OllamaClient;
use App\Services\Assessment\Provider\AiProviderException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http as HttpFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The native Ollama client (Phase 29) against a faked Ollama HTTP API: the
 * request it sends, how every response is normalized, and the cheap health
 * check. No network, no model.
 */
final class OllamaClientTest extends TestCase
{
    private const BASE = 'http://ollama.test:11434';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(array $overrides = []): OllamaClient
    {
        $config = $overrides + [
            'model' => 'qwen2.5-coder:7b', 'base_url' => self::BASE, 'api_key' => '', 'structured_output' => 'json_schema',
            'connect_timeout_seconds' => 3, 'timeout_seconds' => 120, 'max_output_bytes' => 4096, 'context_tokens' => 8192, 'keep_alive' => '5m',
        ];

        return new OllamaClient(new HttpModelTransport(app(Http::class), $config), $config);
    }

    private static function request(): AiRequest
    {
        return new AiRequest('test', 'SYSTEM TEXT', 'USER TEXT', 'codedna_test', ['type' => 'object', 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'boolean']]], 700);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function chat(array $overrides = []): array
    {
        return $overrides + [
            'model' => 'qwen2.5-coder:7b', 'created_at' => '2026-10-09T00:00:00Z',
            'message' => ['role' => 'assistant', 'content' => '{"ok": true}'],
            'done' => true, 'done_reason' => 'stop', 'total_duration' => 4851913680, 'prompt_eval_count' => 37, 'eval_count' => 9,
        ];
    }

    public function test_it_sends_a_bounded_deterministic_structured_request(): void
    {
        HttpFacade::fake([self::BASE.'/api/chat' => HttpFacade::response(self::chat())]);

        $response = $this->client()->complete(self::request());

        $this->assertSame(['{"ok": true}', 'qwen2.5-coder:7b', 37, 9], [$response->content, $response->servedModel, $response->inputTokens, $response->outputTokens]);
        HttpFacade::assertSent(function (Request $request): bool {
            $this->assertSame(self::BASE.'/api/chat', $request->url());
            $this->assertSame([
                'model' => 'qwen2.5-coder:7b',
                'stream' => false,
                'keep_alive' => '5m',
                'messages' => [['role' => 'system', 'content' => 'SYSTEM TEXT'], ['role' => 'user', 'content' => 'USER TEXT']],
                'options' => ['temperature' => 0, 'seed' => 0, 'num_predict' => 700, 'num_ctx' => 8192],
                'format' => ['type' => 'object', 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'boolean']]],
            ], $request->data());
            $this->assertFalse($request->hasHeader('Authorization'), 'no key, no Authorization header');

            return true;
        });
    }

    public function test_structured_output_modes_and_an_optional_key(): void
    {
        HttpFacade::fake([self::BASE.'/api/chat' => HttpFacade::response(self::chat())]);
        $this->client(['structured_output' => 'json_object', 'api_key' => 'proxy-key'])->complete(self::request());
        $this->client(['structured_output' => 'none'])->complete(self::request());

        $sent = HttpFacade::recorded();
        $this->assertSame('json', $sent[0][0]->data()['format']);
        $this->assertSame(['Bearer proxy-key'], $sent[0][0]->header('Authorization'));
        $this->assertArrayNotHasKey('format', $sent[1][0]->data());
    }

    public function test_token_counts_are_unknown_when_ollama_does_not_report_them(): void
    {
        HttpFacade::fake([self::BASE.'/api/chat' => HttpFacade::response(array_diff_key(self::chat(), ['prompt_eval_count' => 0, 'eval_count' => 0]))]);

        $response = $this->client()->complete(self::request());

        $this->assertSame([null, null], [$response->inputTokens, $response->outputTokens]);
    }

    /**
     * @return iterable<string, array{mixed, AssessmentFailure, bool, string}>
     */
    public static function failures(): iterable
    {
        yield 'unknown model (404)' => [fn () => HttpFacade::response(['error' => "model 'x' not found"], 404), AssessmentFailure::ProviderRejected, false, 'model_not_found'];
        yield 'bad request (400)' => [fn () => HttpFacade::response(['error' => 'invalid'], 400), AssessmentFailure::ProviderRejected, false, 'request_rejected'];
        yield 'proxy auth (401)' => [fn () => HttpFacade::response('', 401), AssessmentFailure::ProviderAuthFailed, false, 'auth_rejected'];
        yield 'busy (503)' => [fn () => HttpFacade::response('', 503), AssessmentFailure::ProviderUnavailable, true, 'server_error'];
        yield 'out of memory (500)' => [fn () => HttpFacade::response(['error' => 'model requires more system memory'], 500), AssessmentFailure::ProviderUnavailable, true, 'server_error'];
        yield 'rate limited (429)' => [fn () => HttpFacade::response('', 429, ['Retry-After' => '7']), AssessmentFailure::ProviderRateLimited, true, 'rate_limited'];
        yield 'a redirect' => [fn () => HttpFacade::response('', 302, ['Location' => 'http://169.254.169.254/']), AssessmentFailure::ProviderRejected, false, 'request_rejected'];
        yield 'truncated at num_predict' => [fn () => HttpFacade::response(self::chat(['done_reason' => 'length'])), AssessmentFailure::OutputTooLarge, false, 'truncated'];
        yield 'not done' => [fn () => HttpFacade::response(self::chat(['done' => false])), AssessmentFailure::InvalidOutput, false, 'incomplete'];
        yield 'an error in a 200' => [fn () => HttpFacade::response(self::chat(['error' => 'boom'])), AssessmentFailure::ProviderRejected, false, 'runtime_error'];
        yield 'empty content' => [fn () => HttpFacade::response(self::chat(['message' => ['role' => 'assistant', 'content' => '  ']])), AssessmentFailure::InvalidOutput, false, 'content_missing'];
        yield 'not JSON' => [fn () => HttpFacade::response('<html>', 200), AssessmentFailure::InvalidOutput, false, 'envelope_not_json'];
        yield 'no message' => [fn () => HttpFacade::response(['done' => true]), AssessmentFailure::InvalidOutput, false, 'envelope_invalid'];
        yield 'a huge body' => [fn () => HttpFacade::response(str_repeat('x', 30000)), AssessmentFailure::OutputTooLarge, false, 'envelope_too_large'];
        yield 'a huge declared length' => [fn () => HttpFacade::response('{}', 200, ['Content-Length' => '999999999']), AssessmentFailure::OutputTooLarge, false, 'envelope_too_large'];
    }

    #[DataProvider('failures')]
    public function test_failures_are_normalized_without_their_bodies(Closure $response, AssessmentFailure $failure, bool $retryable, string $detail): void
    {
        HttpFacade::fake([self::BASE.'/api/chat' => $response()]);

        try {
            $this->client()->complete(self::request());
            $this->fail('expected a provider exception');
        } catch (AiProviderException $e) {
            $this->assertSame([$failure, $retryable, $detail], [$e->failure, $e->retryable, $e->detail]);
            $this->assertStringNotContainsString('memory', $e->getMessage());
            $this->assertStringNotContainsString('169.254', $e->getMessage());
        }
        HttpFacade::assertSentCount(1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function transportFailures(): iterable
    {
        yield 'timeout' => ['cURL error 28: Operation timed out', 'transport_timeout'];
        yield 'refused' => ['cURL error 7: Failed to connect', 'transport_error'];
    }

    #[DataProvider('transportFailures')]
    public function test_transport_failures_are_retryable(string $message, string $detail): void
    {
        HttpFacade::fake(fn () => throw new ConnectionException($message));
        try {
            $this->client()->complete(self::request());
            $this->fail('expected a provider exception');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame($detail, $e->detail);
        }
    }

    public function test_slow_local_inference_is_bounded_by_the_configured_timeout_not_php_defaults(): void
    {
        // The pending request carries read_timeout = timeout (PHP streams would otherwise stop at default_socket_timeout).
        $pending = (new HttpModelTransport(app(Http::class), ['connect_timeout_seconds' => 3, 'timeout_seconds' => 240, 'api_key' => '']))->request();
        $this->assertSame(240, $pending->getOptions()['read_timeout']);
        $this->assertSame(240, $pending->getOptions()['timeout']);
        $this->assertFalse($pending->getOptions()['allow_redirects']);
    }

    public function test_health_checks_the_runtime_and_the_model_without_generating(): void
    {
        HttpFacade::fake([
            self::BASE.'/api/version' => HttpFacade::response(['version' => '0.12.6']),
            self::BASE.'/api/tags' => HttpFacade::response(['models' => [['name' => 'qwen2.5-coder:7b', 'model' => 'qwen2.5-coder:7b'], ['name' => 'llama3:latest']]]),
        ]);

        $this->assertSame(['reachable' => true, 'model_available' => true, 'runtime_version' => '0.12.6', 'error' => null], $this->client()->health()->toArray());
        $this->assertTrue($this->client(['model' => 'llama3'])->health()->modelAvailable, 'an untagged name means :latest');
        $this->assertSame([false, 'model_not_found'], [$this->client(['model' => 'qwen3-coder:30b'])->health()->modelAvailable, $this->client(['model' => 'qwen3-coder:30b'])->health()->error]);
        HttpFacade::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/api/chat') || str_contains($r->url(), '/api/pull') || str_contains($r->url(), '/api/generate'));
    }

    public function test_an_unreachable_runtime_is_reported_safely(): void
    {
        HttpFacade::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to ollama port 11434'));

        $health = $this->client()->health();

        $this->assertSame(['reachable' => false, 'model_available' => null, 'runtime_version' => null, 'error' => 'transport_error'], $health->toArray());
    }
}
