<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Services\Ai\AiGateway;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiStatus;
use App\Services\Analyzer\JsonSchemaValidator;
use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Insights\InsightEvalSet;
use App\Services\Insights\InsightException;
use App\Services\Insights\InsightResponseValidator;
use App\Services\Insights\InsightSpecification;
use Illuminate\Console\Command;

/**
 * php artisan ai:eval (Phase 30, docs/operations/local-ai.md#evaluation):
 * model usefulness on the versioned evaluation set. Each case's evidence is
 * sent to the configured model through the gateway, exactly as an insight
 * would be; the answer is validated and the outcome, rejection rule,
 * duration and token counts are reported. Nothing is stored, no quota is
 * used and no user data is read. Run on demand only: it makes one
 * generation per case.
 */
final class AiEvalCommand extends Command
{
    protected $signature = 'ai:eval {--case=* : Only these case ids} {--json : Print JSON}';

    protected $description = 'Send the AI evaluation set to the configured model and report acceptance, rejection reasons and latency';

    public function handle(AiGateway $gateway, AiStatus $status): int
    {
        if (! $status->enabled()) {
            $this->error('AI is disabled (AI_ENABLED=false).');

            return self::FAILURE;
        }
        $spec = new InsightSpecification;
        $validator = new InsightResponseValidator(new JsonSchemaValidator);
        $only = (array) $this->option('case');
        $rows = [];
        foreach (InsightEvalSet::cases() as $case) {
            if ($only !== [] && ! in_array($case['id'], $only, true)) {
                continue;
            }
            $request = new AiRequest(strtolower($case['kind']->value), $spec->systemPrompt($case['kind']), $spec->userMessage($case['input']),
                'codedna_insight_v1', $spec->providerSchema($case['input']), (int) config('codedna.ai.max_output_tokens'));
            $started = microtime(true);
            $row = ['case' => $case['id'], 'kind' => $case['kind']->value, 'outcome' => 'accepted', 'rule' => null, 'duration_ms' => 0, 'input_tokens' => null, 'output_tokens' => null];
            try {
                $response = $gateway->complete($request);
                $row['input_tokens'] = $response->inputTokens;
                $row['output_tokens'] = $response->outputTokens;
                $validator->validate($response->content, $case['input'], $spec, (int) config('codedna.ai.max_output_bytes'));
            } catch (InsightException $e) {
                $row['outcome'] = 'rejected';
                $row['rule'] = $e->detail;
            } catch (AiProviderException $e) {
                $row['outcome'] = 'error';
                $row['rule'] = $e->failure->value.' '.$e->detail;
            }
            $row['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            $rows[] = $row;
        }

        $accepted = count(array_filter($rows, fn (array $r): bool => $r['outcome'] === 'accepted'));
        $summary = [
            'model' => $gateway->model(),
            'provider' => $gateway->provider(),
            'dataset' => InsightEvalSet::PATH,
            'cases' => count($rows),
            'accepted' => $accepted,
            'rejected' => count(array_filter($rows, fn (array $r): bool => $r['outcome'] === 'rejected')),
            'errors' => count(array_filter($rows, fn (array $r): bool => $r['outcome'] === 'error')),
            'total_duration_ms' => array_sum(array_column($rows, 'duration_ms')),
        ];
        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'cases' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Case', 'Kind', 'Outcome', 'Rule', 'ms', 'Tokens in/out'], array_map(fn (array $r): array => [
                $r['case'], $r['kind'], $r['outcome'], $r['rule'] ?? '', $r['duration_ms'], ($r['input_tokens'] ?? '?').'/'.($r['output_tokens'] ?? '?'),
            ], $rows));
            $this->line(sprintf('Model %s (%s): %d of %d accepted, %d rejected, %d errors.', $summary['model'], $summary['provider'], $accepted, $summary['cases'], $summary['rejected'], $summary['errors']));
            $this->line('A rejection is the validator discarding an answer it cannot check against the evidence: correct behavior, but a sign the model is less useful.');
        }

        return $summary['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
