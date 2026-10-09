<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Assessment\AssessmentFailure;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiMetrics;
use App\Services\Ai\AiRequest;
use App\Services\Ai\GatewayAiProvider;
use App\Services\Ai\ModelClient;
use App\Services\Ai\OllamaClient;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Assessment\Provider\FakeAiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\ScriptedModelClient;
use Tests\TestCase;

/**
 * The AI gateway (Phase 29): one attempt per call, a context budget, a
 * concurrency limit across workers, metrics, health, and no fallback.
 */
final class AiGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['codedna.ai.enabled' => true, 'codedna.ai.slot_wait_seconds' => 1, 'codedna.ai.max_concurrency' => 1]);
        Redis::connection()->del('codedna:ai-slots');
        cache()->flush();
    }

    private static function request(int $userBytes = 100, int $output = 500): AiRequest
    {
        return new AiRequest('test', 'system', str_repeat('x', $userBytes), 'schema', ['type' => 'object'], $output);
    }

    public function test_the_configured_provider_is_used_and_nothing_else(): void
    {
        config(['codedna.ai.provider' => 'ollama', 'codedna.ai.base_url' => 'http://ollama.test:11434']);
        Http::fake(['http://ollama.test:11434/api/chat' => Http::response('', 503), '*' => Http::response('unexpected', 500)]);

        $this->assertInstanceOf(OllamaClient::class, app(ModelClient::class));
        $this->assertInstanceOf(GatewayAiProvider::class, app(AiProvider::class), 'Phase 15 assessments go through the gateway');
        try {
            app(AiGateway::class)->complete(self::request());
            $this->fail('expected a failure');
        } catch (AiProviderException $e) {
            $this->assertSame('server_error', $e->detail);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($r): bool => str_starts_with($r->url(), 'http://ollama.test:11434/'));

        config(['codedna.ai.provider' => 'fake']);
        $this->assertInstanceOf(FakeAiProvider::class, app(AiProvider::class));
    }

    public function test_a_request_that_cannot_fit_the_context_window_is_never_sent(): void
    {
        $model = new ScriptedModelClient('valid');
        $this->app->instance(ModelClient::class, $model);
        config(['codedna.ai.context_tokens' => 4096]);
        $gateway = app(AiGateway::class);

        $this->assertTrue($gateway->fits(self::request(3000, 2000)));
        $this->assertFalse($gateway->fits(self::request(9000, 2000)));
        try {
            $gateway->complete(self::request(9000, 2000));
            $this->fail('expected a refusal');
        } catch (AiProviderException $e) {
            $this->assertSame([AssessmentFailure::InputTooLarge, false, 'context_budget'], [$e->failure, $e->retryable, $e->detail]);
        }
        $this->assertSame([], $model->requests);
    }

    public function test_generations_are_limited_across_workers_and_a_busy_slot_is_retryable(): void
    {
        $model = new ScriptedModelClient('valid');
        $this->app->instance(ModelClient::class, $model);
        // Another worker holds the only slot.
        $held = false;
        Redis::funnel('codedna:ai-slots')->limit(1)->releaseAfter(60)->block(1)->then(function () use (&$held, $model): void {
            $held = true;
            try {
                app(AiGateway::class)->complete(self::request());
                $this->fail('expected slots_busy');
            } catch (AiProviderException $e) {
                $this->assertSame([AssessmentFailure::ProviderUnavailable, true, 'slots_busy'], [$e->failure, $e->retryable, $e->detail]);
            }
            $this->assertSame([], $model->requests);
        });
        $this->assertTrue($held);

        // Free again: it goes through.
        app(AiGateway::class)->complete(new AiRequest('test', 'system', "x\n<<<BEGIN_UNTRUSTED_EVIDENCE_JSON>>>\n{\"kind\":\"ROADMAP_GUIDANCE\",\"evidence\":[]}\n<<<END_UNTRUSTED_EVIDENCE_JSON>>>", 's', [], 100));
        $this->assertCount(1, $model->requests);
        $this->assertSame(1, app(AiMetrics::class)->snapshot()['counts']['SLOTS_BUSY']);
    }

    public function test_metrics_count_outcomes_and_durations_only(): void
    {
        $this->app->instance(ModelClient::class, new ScriptedModelClient('timeout', 'auth'));
        foreach ([1, 2] as $ignored) {
            try {
                app(AiGateway::class)->complete(self::request());
            } catch (AiProviderException) {
            }
        }

        $snapshot = app(AiMetrics::class)->snapshot();
        $this->assertSame(1, $snapshot['counts']['PROVIDER_TIMEOUT']);
        $this->assertSame(1, $snapshot['counts']['PROVIDER_AUTH_FAILED']);
        $this->assertSame(0, $snapshot['in_flight']);
        $this->assertSame(['counts', 'duration_ms_total', 'last_duration_ms', 'in_flight'], array_keys($snapshot));
    }

    public function test_status_command_and_smoke_command(): void
    {
        $model = new ScriptedModelClient(fn (): array => ['ok' => true, 'answer' => 'ready']);
        $this->app->instance(ModelClient::class, $model);

        $this->artisan('ai:status')->assertSuccessful()->expectsOutputToContain('1. Runtime reachable');
        $this->assertSame([], $model->requests, 'the status never generates');
        $this->artisan('ai:smoke')->assertSuccessful()->expectsOutputToContain('structured JSON valid');
        $this->assertCount(1, $model->requests);

        $model->healthy = false;
        cache()->flush();
        $this->artisan('ai:status')->assertFailed();
        config(['codedna.ai.enabled' => false]);
        $this->artisan('ai:smoke')->assertFailed();
        // Disabled is a valid configuration: reported, nothing contacted, success.
        $checks = $model->healthChecks;
        $this->artisan('ai:status')->assertSuccessful()->expectsOutputToContain('not checked (AI disabled)');
        $this->artisan('ai:status', ['--json' => true])->assertSuccessful()->expectsOutputToContain('"enabled": false');
        $this->assertSame($checks, $model->healthChecks, 'a disabled AI is never probed');
    }

    public function test_the_status_endpoint_reports_a_remote_endpoint_as_remote(): void
    {
        $this->app->instance(ModelClient::class, new ScriptedModelClient('valid'));
        $user = User::factory()->create();
        foreach (['http://ollama:11434' => 'local', 'http://localhost:11434' => 'local', 'https://gpu.example.com' => 'remote', 'https://10.0.0.5:11434' => 'remote'] as $url => $kind) {
            config(['codedna.ai.base_url' => $url]);
            $this->asUser($user)->getJson('/api/v1/ai/status')->assertOk()->assertJsonPath('data.endpoint', $kind);
        }
    }
}
