<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Challenge\ChallengeStatus;
use App\Enums\Challenge\SubmissionFailure;
use App\Enums\Challenge\SubmissionStatus;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ends challenge evaluations that can no longer finish
 * (docs/architecture/coding-challenges-v1.md#evaluation):
 *
 * - RUNNING submissions whose lease expired and that were not touched for
 *   stale_after_seconds (the worker crashed or the job was lost);
 * - QUEUED submissions never picked up for queued_stale_after_seconds.
 *
 * They become ERROR (EVALUATION_STALE), which consumes no graded attempt,
 * and their challenge accepts a new attempt. Scheduled every five minutes.
 */
final class FailStaleChallengeEvaluations extends Command
{
    protected $signature = 'challenge:fail-stale';

    protected $description = 'Mark challenge evaluations stuck in QUEUED or RUNNING as ERROR (EVALUATION_STALE)';

    public function handle(ConnectionInterface $db): int
    {
        $now = Carbon::now();
        $candidates = ChallengeSubmission::query()
            ->where(fn ($query) => $query
                ->where(fn ($running) => $running->where('status', SubmissionStatus::Running->value)
                    ->where('updated_at', '<', $now->copy()->subSeconds((int) config('codedna.challenges.stale_after_seconds')))
                    ->where('lease_expires_at', '<', $now))
                ->orWhere(fn ($queued) => $queued->where('status', SubmissionStatus::Queued->value)
                    ->where('updated_at', '<', $now->copy()->subSeconds((int) config('codedna.challenges.queued_stale_after_seconds')))))
            ->pluck('id');

        $failed = 0;
        foreach ($candidates as $id) {
            $failed += (int) $db->transaction(function () use ($id): bool {
                $submission = ChallengeSubmission::query()->whereKey($id)->lockForUpdate()->first();
                if ($submission === null || $submission->status->isTerminal()) {
                    return false;
                }
                $submission->forceFill([
                    'status' => SubmissionStatus::Error,
                    'failure_code' => SubmissionFailure::EvaluationStale->value,
                    'failure_detail' => null,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'completed_at' => Carbon::now(),
                ])->save();
                $challenge = ChallengeInstance::query()->whereKey($submission->challenge_instance_id)->lockForUpdate()->firstOrFail();
                if ($challenge->status === ChallengeStatus::Evaluating) {
                    $challenge->forceFill(['status' => ChallengeStatus::Assigned, 'last_result' => 'ERROR'])->save();
                }
                Log::warning('challenge.evaluation_failed', [
                    'submission_id' => $submission->id,
                    'challenge_id' => $submission->challenge_instance_id,
                    'project_id' => $submission->project_id,
                    'status' => SubmissionStatus::Error->value,
                    'error_code' => SubmissionFailure::EvaluationStale->value,
                ]);

                return true;
            });
        }

        $this->info("Stale challenge evaluations ended: {$failed}");

        return self::SUCCESS;
    }
}
