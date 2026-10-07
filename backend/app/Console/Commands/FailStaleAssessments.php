<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Assessment\AssessmentFailure;
use App\Enums\Assessment\AssessmentStatus;
use App\Models\AiAssessment;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fails AI assessments that can no longer finish
 * (docs/architecture/ai-assessment-v1.md#lifecycle):
 *
 * - RUNNING ones not touched for AI_STALE_AFTER_SECONDS whose lease has
 *   expired (the worker crashed or the job was lost);
 * - QUEUED ones never picked up for AI_QUEUED_STALE_AFTER_SECONDS.
 *
 * Both become FAILED with ASSESSMENT_STALE, which frees the identity for a
 * new request. Scheduled every five minutes. Never touches deterministic
 * snapshots.
 */
final class FailStaleAssessments extends Command
{
    protected $signature = 'assessment:fail-stale';

    protected $description = 'Mark AI assessments that are stuck in QUEUED or RUNNING as FAILED (ASSESSMENT_STALE)';

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
        $candidates = AiAssessment::query()->where($stale)->pluck('id');

        $failed = 0;
        foreach ($candidates as $id) {
            $failed += (int) $db->transaction(function () use ($id, $stale): bool {
                $assessment = AiAssessment::query()->whereKey($id)->where($stale)->lockForUpdate()->first();
                if ($assessment === null || $assessment->status->isTerminal()) {
                    return false;
                }
                $assessment->forceFill([
                    'status' => AssessmentStatus::Failed,
                    'failure_code' => AssessmentFailure::Stale->value,
                    'failure_detail' => null,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'completed_at' => Carbon::now(),
                ])->save();
                Log::warning('assessment.failed', [
                    'assessment_id' => $assessment->id,
                    'project_id' => $assessment->project_id,
                    'status' => AssessmentStatus::Failed->value,
                    'error_code' => AssessmentFailure::Stale->value,
                ]);

                return true;
            });
        }

        $this->info("Stale AI assessments failed: {$failed}");

        return self::SUCCESS;
    }
}
