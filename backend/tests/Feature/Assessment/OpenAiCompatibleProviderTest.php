<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentPrompt;
use App\Services\Assessment\AssessmentSpecification;
use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Assessment\Provider\AiProviderResponse;
use App\Services\Assessment\Provider\OpenAiCompatibleProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http as HttpFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The OpenAI-compatible adapter against a faked HTTP endpoint: the request
 * it sends and how every kind of response is normalized. No network.
 */
final class OpenAiCompatibleProviderTest extends TestCase
{
    private const URL = 'https://llm.example.test/v1/chat/completions';

    private const KEY = 'test-provider-key';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function provider(array $overrides = []): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(app(Http::class), $overrides + [
            'model' => 'test-model-1',
            'base_url' => 'https://llm.example.test/v1',
            'api_key' => self::KEY,
            'structured_output' => 'json_schema',
            'connect_timeout_seconds' => 3,
            'timeout_seconds' => 20,
            'max_output_tokens' => 1500,
            'max_output_bytes' => 4096,
        ]);
    }

    private function generate(?OpenAiCompatibleProvider $provider = null): AiProviderResponse
    {
        $spec = new AssessmentSpecification;
        $input = new AssessmentInput(['project_id' => 'LINEAGE'], ['schema_version' => 'assessment-input/1.0.0', 'evidence' => []]);

        return ($provider ?? $this->provider())->generateAssessment($input, AssessmentPrompt::for($spec, $input));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function completion(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'test-model-1-2026-01-01',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"schema_version":"assessment/v1"}', 'refusal' => null]]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120, 'total_tokens' => 1020],
        ], $overrides);
    }

    public function test_it_sends_one_bounded_deterministic_structured_request(): void
    {
        HttpFacade::fake([self::URL => HttpFacade::response(self::completion())]);

        $response = $this->generate();

        $this->assertSame('{"schema_version":"assessment/v1"}', $response->content);
        $this->assertSame(['served_model' => 'test-model-1-2026-01-01', 'response_id' => 'chatcmpl-123', 'input_tokens' => 900, 'output_tokens' => 120], $response->metadata());
        HttpFacade::assertSentCount(1);
        HttpFacade::assertSent(function (Request $request): bool {
            $body = $request->data();
            $spec = new AssessmentSpecification;
            $this->assertSame('POST', $request->method());
            $this->assertSame(self::URL, $request->url());
            $this->assertSame(['Bearer '.self::KEY], $request->header('Authorization'));
            $this->assertSame(['model', 'temperature', 'max_tokens', 'messages', 'response_format'], array_keys($body));
            $this->assertSame('test-model-1', $body['model']);
            $this->assertSame(0, $body['temperature']);
            $this->assertSame(1500, $body['max_tokens']);
            $this->assertSame([
                ['role' => 'system', 'content' => $spec->systemPrompt()],
                ['role' => 'user', 'content' => "Interpret the following CodeDNA evidence according to the rules.\n<<<BEGIN_UNTRUSTED_EVIDENCE_JSON>>>\n"
                    ."{\"evidence\":[],\"schema_version\":\"assessment-input/1.0.0\"}\n<<<END_UNTRUSTED_EVIDENCE_JSON>>>\nReturn only the JSON object."],
            ], $body['messages']);
            $this->assertSame(['type' => 'json_schema', 'json_schema' => ['name' => 'codedna_assessment_v1', 'strict' => true, 'schema' => $spec->providerSchema()]], $body['response_format']);
            $this->assertStringNotContainsString('LINEAGE', (string) json_encode($body), 'lineage is never sent');

            return true;
        });
    }

    public function test_no_authorization_header_is_sent_without_a_key(): void
    {
        HttpFacade::fake([self::URL => HttpFacade::response(self::completion())]);

        $this->generate($this->provider(['api_key' => '']));

        HttpFacade::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_servers_without_structured_output_get_json_mode_or_nothing(): void
    {
        HttpFacade::fake([self::URL => HttpFacade::response(self::completion())]);

        $this->generate($this->provider(['structured_output' => 'json_object']));
        $this->generate($this->provider(['structured_output' => 'none']));

        $bodies = HttpFacade::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();
        $this->assertSame(['type' => 'json_object'], $bodies[0]['response_format']);
        $this->assertArrayNotHasKey('response_format', $bodies[1]);
    }

    /**
     * @return iterable<string, array{int, array<string, string>, AssessmentFailure, bool, int|null}>
     */
    public static function errorStatuses(): iterable
    {
        yield '429 with Retry-After' => [429, ['Retry-After' => '30'], AssessmentFailure::ProviderRateLimited, true, 30];
        yield '429 without Retry-After' => [429, [], AssessmentFailure::ProviderRateLimited, true, null];
        yield '500' => [500, [], AssessmentFailure::ProviderUnavailable, true, null];
        yield '502' => [502, [], AssessmentFailure::ProviderUnavailable, true, null];
        yield '503 with Retry-After' => [503, ['Retry-After' => '12'], AssessmentFailure::ProviderUnavailable, true, 12];
        yield '408' => [408, [], AssessmentFailure::ProviderUnavailable, true, null];
        yield '401' => [401, [], AssessmentFailure::ProviderAuthFailed, false, null];
        yield '403' => [403, [], AssessmentFailure::ProviderAuthFailed, false, null];
        yield '400' => [400, [], AssessmentFailure::ProviderRejected, false, null];
        yield '404' => [404, [], AssessmentFailure::ProviderRejected, false, null];
        yield '422' => [422, [], AssessmentFailure::ProviderRejected, false, null];
        yield 'redirect is not followed' => [302, ['Location' => 'https://elsewhere.example.test/'], AssessmentFailure::ProviderRejected, false, null];
        yield 'unparseable Retry-After' => [429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], AssessmentFailure::ProviderRateLimited, true, null];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('errorStatuses')]
    public function test_error_statuses_are_normalized(int $status, array $headers, AssessmentFailure $failure, bool $retryable, ?int $retryAfter): void
    {
        HttpFacade::fake([self::URL => HttpFacade::response(['error' => ['message' => 'secret-ish provider text']], $status, $headers)]);

        $e = $this->expectFailure();

        $this->assertSame($failure, $e->failure);
        $this->assertSame($retryable, $e->retryable);
        $this->assertSame($status, $e->status);
        $this->assertSame($retryAfter, $e->retryAfterSeconds);
        $this->assertStringNotContainsString('provider text', $e->getMessage());
        HttpFacade::assertSentCount(1);
    }

    public function test_a_timeout_is_retryable(): void
    {
        HttpFacade::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds'));

        $e = $this->expectFailure();

        $this->assertSame([AssessmentFailure::ProviderTimeout, true, 'transport_timeout'], [$e->failure, $e->retryable, $e->detail]);
    }

    public function test_a_connection_failure_is_retryable(): void
    {
        HttpFacade::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to llm.example.test port 443'));

        $e = $this->expectFailure();

        $this->assertSame([AssessmentFailure::ProviderUnavailable, true, 'transport_error'], [$e->failure, $e->retryable, $e->detail]);
        $this->assertStringNotContainsString('llm.example.test', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string|array<string, mixed>, AssessmentFailure, string}>
     */
    public static function badEnvelopes(): iterable
    {
        yield 'truncated by max_tokens' => [self::completion(['choices' => [['finish_reason' => 'length']]]), AssessmentFailure::OutputTooLarge, 'truncated'];
        yield 'refusal' => [self::completion(['choices' => [['message' => ['content' => null, 'refusal' => 'I cannot help with that.']]]]), AssessmentFailure::ProviderRejected, 'refusal'];
        yield 'no content' => [self::completion(['choices' => [['message' => ['content' => null]]]]), AssessmentFailure::InvalidOutput, 'content_missing'];
        yield 'empty content' => [self::completion(['choices' => [['message' => ['content' => '']]]]), AssessmentFailure::InvalidOutput, 'content_missing'];
        yield 'no choices' => [['id' => 'x', 'choices' => []], AssessmentFailure::InvalidOutput, 'envelope_invalid'];
        yield 'not json' => ['<html>gateway</html>', AssessmentFailure::InvalidOutput, 'envelope_not_json'];
        yield 'oversized envelope' => [self::completion(['choices' => [['message' => ['content' => str_repeat('a', 30000)]]]]), AssessmentFailure::OutputTooLarge, 'envelope_too_large'];
    }

    /**
     * @param  string|array<string, mixed>  $body
     */
    #[DataProvider('badEnvelopes')]
    public function test_unusable_successful_responses_are_permanent_failures(string|array $body, AssessmentFailure $failure, string $detail): void
    {
        HttpFacade::fake([self::URL => HttpFacade::response($body)]);

        $e = $this->expectFailure();

        $this->assertSame([$failure, false, $detail], [$e->failure, $e->retryable, $e->detail]);
    }

    public function test_unsafe_metadata_is_dropped(): void
    {
        HttpFacade::fake([self::URL => HttpFacade::response(self::completion(['model' => "model\nwith newline", 'id' => str_repeat('x', 200), 'usage' => ['prompt_tokens' => -1, 'completion_tokens' => 'many']]))]);

        $this->assertSame(['served_model' => null, 'response_id' => null, 'input_tokens' => null, 'output_tokens' => null], $this->generate()->metadata());
    }

    private function expectFailure(): AiProviderException
    {
        try {
            $this->generate();
        } catch (AiProviderException $e) {
            return $e;
        }
        $this->fail('Expected an AiProviderException.');
    }
}
