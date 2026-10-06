<?php

declare(strict_types=1);

namespace App\Actions\Analysis;

use App\Enums\AnalysisRunStatus;
use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Services\Analyzer\AnalyzerResult;
use Illuminate\Database\ConnectionInterface;

/**
 * Persists a verified analyzer result (docs/architecture/data-flow.md#persistence-of-a-result),
 * in one transaction:
 *
 * 1. lock the run row; if it is no longer RUNNING (already SUCCEEDED,
 *    FAILED or CANCELLED, e.g. by a duplicate job or the stale sweeper),
 *    store nothing and report false (idempotency, internal contract §6);
 * 2. mark the run SUCCEEDED with its versions and result_hash;
 * 3. insert the immutable analysis_results row.
 *
 * Never creates a DNA snapshot: static analysis is not scoring (Phase 11).
 */
final readonly class PersistAnalysisResult
{
    public function __construct(private ConnectionInterface $db) {}

    public function handle(string $analysisRunId, AnalyzerResult $result): bool
    {
        return $this->db->transaction(function () use ($analysisRunId, $result): bool {
            $run = AnalysisRun::query()->whereKey($analysisRunId)->lockForUpdate()->first();
            if ($run === null || $run->status !== AnalysisRunStatus::Running || $run->result_type !== $result->resultType) {
                return false;
            }

            // The job's lease ends with the run.
            $metadata = $run->metadata ?? [];
            unset($metadata['lease']);
            $run->metadata = $metadata === [] ? null : $metadata;
            $run->markSucceeded($result->versions, $result->resultHash);

            $stored = new AnalysisResult;
            $stored->forceFill([
                'analysis_run_id' => $run->id,
                'result_type' => $result->resultType,
                'result_hash' => $result->resultHash,
                'contract_version' => $result->versions->contract,
                'analyzer_version' => $result->versions->analyzer,
                'ir_version' => $result->versions->ir,
                'metrics_version' => $result->versions->metrics,
                // The verified bytes, not a re-encoding (which could turn 1.0 into 1).
                'result' => $result->rawBody,
                'size_bytes' => $result->sizeBytes,
            ]);
            $stored->save();

            return true;
        });
    }
}
