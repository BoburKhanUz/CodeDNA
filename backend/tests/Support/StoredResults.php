<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AnalysisResultType;
use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Models\SourceSnapshot;
use App\Services\Analyzer\CanonicalJson;
use App\Support\AnalysisVersions;
use stdClass;

/**
 * SUCCEEDED analysis runs with a verified result persisted exactly as
 * PersistAnalysisResult stores it: the captured analyzer response
 * (tests/Fixtures/analyzer), with the run's ID and a recomputed result_hash.
 */
final class StoredResults
{
    /**
     * @param  (callable(stdClass): void)|null  $mutate  changes the result before it is hashed
     */
    public static function succeededRun(string $resultType = 'static_analysis', ?callable $mutate = null, ?SourceSnapshot $snapshot = null): AnalysisRun
    {
        $type = AnalysisResultType::from($resultType);
        $run = AnalysisRun::factory()->running()->for($snapshot ?? SourceSnapshot::factory())->create(['result_type' => $type]);

        $result = FakeAnalyzer::fixture($resultType);
        $result->analysis_run_id = $run->id;
        $result->request_id = '00000000-0000-4000-8000-000000000000';
        if ($mutate !== null) {
            $mutate($result);
        }
        $hashed = clone $result;
        unset($hashed->request_id, $hashed->diagnostics, $hashed->result_hash);
        $result->result_hash = CanonicalJson::hash($hashed);
        $raw = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        $versions = $result->versions;
        $run->markSucceeded(new AnalysisVersions(
            analyzer: $versions->analyzer,
            ir: $versions->ir,
            metrics: is_string($versions->metrics) ? $versions->metrics : null,
            scoring: null,
            contract: $result->contract_version,
        ), $result->result_hash);

        $stored = new AnalysisResult;
        $stored->forceFill([
            'analysis_run_id' => $run->id,
            'result_type' => $type,
            'result_hash' => $result->result_hash,
            'contract_version' => $result->contract_version,
            'analyzer_version' => $versions->analyzer,
            'ir_version' => $versions->ir,
            'metrics_version' => is_string($versions->metrics) ? $versions->metrics : null,
            'result' => $raw,
            'size_bytes' => strlen($raw),
        ])->save();

        return $run->refresh();
    }

    /**
     * Sets metrics.overall counts (and finding counts by rule) on a result.
     *
     * @param  array<string, int|null>  $overall
     * @param  array<string, int>  $byRule
     */
    public static function set(stdClass $result, array $overall = [], array $byRule = []): void
    {
        foreach ($overall as $key => $value) {
            $result->metrics->overall->{$key} = $value;
        }
        foreach ($byRule as $rule => $count) {
            $result->findings->by_rule->{$rule} = $count;
        }
    }
}
