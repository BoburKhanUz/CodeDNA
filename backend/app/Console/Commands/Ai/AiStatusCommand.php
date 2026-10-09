<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Services\Ai\AiMetrics;
use App\Services\Ai\AiStatus;
use App\Services\Ai\ModelClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan ai:status (Phase 29, docs/operations/local-ai.md#health): the
 * configuration, a fresh connectivity and model availability check (no
 * generation), the gateway counters and the pending AI work. For
 * operators; prints no prompt, evidence, answer or key.
 */
final class AiStatusCommand extends Command
{
    protected $signature = 'ai:status {--json : Print JSON}';

    protected $description = 'Show local AI configuration, connectivity, model availability and gateway metrics (no generation)';

    public function handle(AiStatus $status, ModelClient $client, AiMetrics $metrics): int
    {
        // Disabled is a valid configuration: report it, contact nothing, succeed.
        $health = $status->enabled() ? $status->health(fresh: true) : null;
        $pending = [];
        foreach (['ai_assessments', 'ai_insights'] as $table) {
            $pending[$table] = DB::table($table)->whereIn('status', ['QUEUED', 'RUNNING'])->count();
        }
        $report = [
            'enabled' => $status->enabled(),
            'provider' => $client->name(),
            'endpoint' => $status->endpointKind(),
            'model' => $client->model(),
            'context_tokens' => (int) config('codedna.ai.context_tokens'),
            'max_concurrency' => (int) config('codedna.ai.max_concurrency'),
            'health' => $health?->toArray(),
            'metrics' => $metrics->snapshot(),
            'pending' => $pending,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Result'], [
                ['AI enabled', $report['enabled'] ? 'yes' : 'no'],
                ['Provider / endpoint', "{$report['provider']} ({$report['endpoint']})"],
                ['Model', $report['model']],
                ['1. Runtime reachable', match (true) {
                    $health === null => 'not checked (AI disabled)',
                    $health->reachable => 'yes'.($health->runtimeVersion !== null ? " (version {$health->runtimeVersion})" : ''),
                    default => 'NO ('.($health->error ?? 'unknown').')',
                }],
                ['2. Model available', match ($health?->modelAvailable) {
                    true => 'yes', false => 'NO (pull it: see docs/operations/local-ai.md)', null => $health === null ? 'not checked (AI disabled)' : 'unknown'
                }],
                ['3. Minimal generation', 'not checked here: php artisan ai:smoke'],
                ['In flight / pending', $report['metrics']['in_flight'].' / '.array_sum($pending)],
            ]);
            $this->table(['Outcome', 'Count'], array_map(fn (string $k, int $v): array => [$k, $v], array_keys($report['metrics']['counts']), $report['metrics']['counts']));
        }

        return $health === null || ($health->reachable && $health->modelAvailable !== false) ? self::SUCCESS : self::FAILURE;
    }
}
