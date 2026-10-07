<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisFailure;
use App\Enums\AnalysisRunStatus;
use App\Models\AnalysisRun;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fails analysis runs that can no longer finish (docs/architecture/data-flow.md#stale-runs):
 *
 * - RUNNING runs not touched for ANALYSIS_STALE_AFTER_SECONDS (every attempt
 *   with its backoff fits well inside it): the worker crashed or the job was
 *   lost;
 * - QUEUED runs never picked up for ANALYSIS_QUEUED_STALE_AFTER_SECONDS: the
 *   job never reached a worker.
 *
 * Both become FAILED with ANALYSIS_STALE, which also frees the (snapshot,
 * result type) pair for a new run. Scheduled every five minutes.
 */
final class FailStaleAnalyses extends Command
{
    protected $signature = 'analysis:fail-stale';

    protected $description = 'Mark analysis runs that are stuck in QUEUED or RUNNING as FAILED (ANALYSIS_STALE)';

    public function handle(ConnectionInterface $db): int
    {
        $now = Carbon::now();
        // The same condition selects the candidates and is checked again on the locked row,
        // so a run a worker renewed in between is never failed.
        $stale = fn ($query) => $query
            ->where(fn ($running) => $running->where('status', AnalysisRunStatus::Running->value)
                ->where('updated_at', '<', $now->copy()->subSeconds((int) config('codedna.analysis.stale_after_seconds'))))
            ->orWhere(fn ($queued) => $queued->where('status', AnalysisRunStatus::Queued->value)
                ->where('updated_at', '<', $now->copy()->subSeconds((int) config('codedna.analysis.queued_stale_after_seconds'))));
        $candidates = AnalysisRun::query()->where($stale)->pluck('id');

        $failed = 0;
        foreach ($candidates as $id) {
            $failed += (int) $db->transaction(function () use ($id, $stale): bool {
                $run = AnalysisRun::query()->whereKey($id)->where($stale)->lockForUpdate()->first();
                if ($run === null || $run->status->isTerminal()) {
                    return false;
                }
                $metadata = $run->metadata ?? [];
                unset($metadata['lease']);
                $run->metadata = $metadata === [] ? null : $metadata;
                $run->markFailed(AnalysisFailure::AnalysisStale->value, AnalysisFailure::AnalysisStale->message());
                Log::warning('analysis.failed', [
                    'analysis_run_id' => $run->id,
                    'project_id' => $run->project_id,
                    'source_snapshot_id' => $run->source_snapshot_id,
                    'result_type' => $run->result_type->value,
                    'status' => AnalysisRunStatus::Failed->value,
                    'error_code' => AnalysisFailure::AnalysisStale->value,
                ]);

                return true;
            });
        }

        $this->info("Stale analysis runs failed: {$failed}");

        return self::SUCCESS;
    }
}
