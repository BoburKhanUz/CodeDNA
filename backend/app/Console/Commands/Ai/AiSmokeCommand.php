<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Services\Ai\AiGateway;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiStatus;
use App\Services\Assessment\Provider\AiProviderException;
use Illuminate\Console\Command;
use JsonException;

/**
 * php artisan ai:smoke (Phase 29, docs/operations/local-ai.md#health): one
 * minimal generation through the gateway, with a tiny fixed prompt and
 * schema, to prove that the configured model answers with structured JSON.
 * Run on demand only (never by health checks). Proves level 3 of 4: it does
 * not prove that full insights pass validation.
 */
final class AiSmokeCommand extends Command
{
    protected $signature = 'ai:smoke';

    protected $description = 'Run one minimal structured generation against the configured model';

    public function handle(AiGateway $gateway, AiStatus $status): int
    {
        if (! $status->enabled()) {
            $this->error('AI is disabled (AI_ENABLED=false).');

            return self::FAILURE;
        }
        $request = new AiRequest(
            'smoke',
            'You answer with a single JSON object that matches the schema, and nothing else.',
            'Return {"ok": true, "answer": "ready"}.',
            'codedna_smoke',
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['ok', 'answer'], 'properties' => ['ok' => ['type' => 'boolean'], 'answer' => ['type' => 'string']]],
            64,
        );
        $started = microtime(true);
        try {
            $response = $gateway->complete($request);
        } catch (AiProviderException $e) {
            $this->error("Generation failed: {$e->failure->value} ({$e->detail}).");

            return self::FAILURE;
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        try {
            $decoded = json_decode(trim($response->content), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        $ok = is_array($decoded) && ($decoded['ok'] ?? null) === true;
        $this->line(sprintf('Model %s answered in %d ms; structured JSON %s; tokens in/out: %s/%s.',
            $gateway->model(), $ms, $ok ? 'valid' : 'INVALID',
            $response->inputTokens ?? 'unknown', $response->outputTokens ?? 'unknown'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
