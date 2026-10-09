<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Assessment\AssessmentStatus;
use App\Enums\Insights\InsightFailure;
use App\Models\AiInsight;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fails AI insights that can no longer finish (Phase 29,
 * docs/architecture/ai-intelligence-v1.md#lifecycle), exactly as
 * assessment:fail-stale does for assessments: RUNNING ones with an expired
 * lease untouched for AI_STALE_AFTER_SECONDS, and QUEUED ones never picked
 * up for AI_QUEUED_STALE_AFTER_SECONDS, become FAILED with INSIGHT_STALE
 * (refunded like any failure). Scheduled every five minutes. Never touches
 * deterministic results.
 */
final class FailStaleInsights extends Command
{
    protected $signature = 'insight:fail-stale';

    protected $description = 'Mark AI insights that are stuck in QUEUED or RUNNING as FAILED (INSIGHT_STALE)';

    public function handle(ConnectionInterface $db): int
    {
        $now = Carbon::now();
        // The same condition selects the candidates and is checked again on the locked row,
        // so a run a worker renewed in between is never failed.
        $stale = fn ($query) => $query
            ->where(fn ($running) => $running->where('status', AssessmentStatus::Running->value)
                ->where('updated_at', '<', $now->copy()->subSeconds((int) config('codedna.ai.stale_after_seconds')))
                ->where('lease_expires_at', '<', $now))
            ->orWhere(fn ($queued) => $queued->where('status', AssessmentStatus::Queued->value)
                ->where('updated_at', '<', $now->copy()->subSeconds((int) config('codedna.ai.queued_stale_after_seconds'))));
        $candidates = AiInsight::query()->where($stale)->pluck('id');

        $failed = 0;
        foreach ($candidates as $id) {
            $failed += (int) $db->transaction(function () use ($id, $stale): bool {
                $insight = AiInsight::query()->whereKey($id)->where($stale)->lockForUpdate()->first();
                if ($insight === null || $insight->status->isTerminal()) {
                    return false;
                }
                $insight->forceFill([
                    'status' => AssessmentStatus::Failed,
                    'failure_code' => InsightFailure::Stale->value,
                    'failure_detail' => null,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'completed_at' => Carbon::now(),
                ])->save();
                Log::warning('insight.failed', [
                    'insight_id' => $insight->id,
                    'project_id' => $insight->project_id,
                    'status' => AssessmentStatus::Failed->value,
                    'error_code' => InsightFailure::Stale->value,
                ]);

                return true;
            });
        }

        $this->info("Stale AI insights failed: {$failed}");

        return self::SUCCESS;
    }
}
