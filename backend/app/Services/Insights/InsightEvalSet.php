<?php

declare(strict_types=1);

namespace App\Services\Insights;

use App\Enums\Insights\InsightKind;
use App\Services\Insights\Evidence\Facts;
use JsonException;
use RuntimeException;

/**
 * The versioned AI insight evaluation set (Phase 30,
 * resources/ai-eval/insight-eval-v1.json, docs/operations/local-ai.md#evaluation):
 * fixed evidence per scenario, a fixed model output and the validator
 * decision it must produce. The unit test proves the validator's decisions;
 * `php artisan ai:eval` sends the same evidence to the configured model.
 */
final class InsightEvalSet
{
    public const PATH = 'ai-eval/insight-eval-v1.json';

    /**
     * @return list<array{id: string, scenario: string, kind: InsightKind, input: InsightInput, output: string, expected: array{outcome: string, rule?: string}}>
     */
    public static function cases(?string $path = null): array
    {
        try {
            $data = json_decode((string) file_get_contents($path ?? resource_path(self::PATH)), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('The AI evaluation set is not valid JSON.', 0, $e);
        }
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 'ai-insight-eval/1' || ! is_array($data['cases'] ?? null)) {
            throw new RuntimeException('Unsupported AI evaluation set.');
        }
        $cases = [];
        foreach ($data['cases'] as $case) {
            $kind = InsightKind::from((string) $case['kind']);
            // Built through Facts::item, like the real evidence builders.
            $evidence = array_map(fn (array $item): array => Facts::item((string) $item['id'], (string) $item['label'], (string) $item['description'], (array) $item['facts']), $case['evidence']);
            $cases[] = [
                'id' => (string) $case['id'],
                'scenario' => (string) $case['scenario'],
                'kind' => $kind,
                'input' => new InsightInput($kind, [
                    'schema_version' => InsightSpecification::INPUT_SCHEMA_VERSION,
                    'insight_version' => (string) $data['insight_version'],
                    'kind' => $kind->value,
                    'notes' => [],
                    'evidence' => $evidence,
                ], []),
                'output' => isset($case['raw_output']) ? (string) $case['raw_output'] : (string) json_encode($case['output'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'expected' => $case['expected'],
            ];
        }

        return $cases;
    }
}
