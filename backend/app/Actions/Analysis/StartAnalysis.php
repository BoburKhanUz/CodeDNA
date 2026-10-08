<?php

declare(strict_types=1);

namespace App\Actions\Analysis;

use App\Enums\AnalysisFailure;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Enums\Billing\QuotaKey;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Billing\UsageService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starts one logical analysis: a (source snapshot, result type) pair of a
 * project (docs/architecture/data-flow.md#idempotency-policy).
 *
 * - An equivalent run that is QUEUED, RUNNING or SUCCEEDED is returned as is
 *   (no new run, no new job): repeating a request never duplicates work, and
 *   a successful result is never recomputed silently.
 * - Otherwise (no run yet, or only FAILED/CANCELLED ones) a new QUEUED run is
 *   created and its job dispatched after the transaction commits. Earlier
 *   failed runs stay as history.
 *
 * Concurrency: the project row is locked for the decision, so concurrent
 * starts serialize; the partial unique index on active runs per (snapshot,
 * result type) is the database backstop.
 */
final readonly class StartAnalysis
{
    public function __construct(
        private ConnectionInterface $db,
        private Dispatcher $dispatcher,
        private UsageService $usage,
    ) {}

    public function handle(Project $project, User $actor, string $sourceSnapshotId, AnalysisResultType $resultType): StartedAnalysis
    {
        try {
            $started = $this->db->transaction(fn (): StartedAnalysis => $this->resolve($project, $actor, $sourceSnapshotId, $resultType));
        } catch (UniqueConstraintViolationException) {
            // Only reachable if the lock was bypassed; the index kept one active run.
            $started = new StartedAnalysis($this->equivalent($sourceSnapshotId, $resultType) ?? throw new ApiException(ErrorCode::InternalError), false);
        }

        if ($started->created) {
            $this->dispatch($started->run);
        }

        return $started;
    }

    private function resolve(Project $project, User $actor, string $sourceSnapshotId, AnalysisResultType $resultType): StartedAnalysis
    {
        $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and cannot start new analyses.');
        }

        $snapshot = SourceSnapshot::query()->whereKey($sourceSnapshotId)->where('project_id', $locked->id)->first();
        if ($snapshot === null) {
            // Same answer for "does not exist" and "belongs to another project or user".
            throw ValidationException::withMessages(['source_snapshot_id' => 'The selected source snapshot is invalid.']);
        }

        $existing = $this->equivalent($snapshot->id, $resultType);
        if ($existing !== null) {
            return new StartedAnalysis($existing, false);
        }

        $run = new AnalysisRun;
        $run->id = $run->newUniqueId();
        $run->forceFill([
            'project_id' => $locked->id,
            'source_snapshot_id' => $snapshot->id,
            'result_type' => $resultType,
            'status' => AnalysisRunStatus::Queued,
            // Sent to the analyzer as Idempotency-Key (ADR-005).
            'idempotency_key' => $run->id,
            'metadata' => array_filter([
                'requested_by' => $actor->getKey(),
                'request_id' => Context::get('request_id'),
            ]),
        ]);
        $run->save();
        // Billing (Phase 23): a new run uses one analysis of the owner's plan
        // (refunded if it ends FAILED or CANCELLED); a reused run uses none.
        $this->usage->consume($locked, QuotaKey::Analyses, 'analysis_run', $run->id);

        return new StartedAnalysis($run, true);
    }

    private function equivalent(string $sourceSnapshotId, AnalysisResultType $resultType): ?AnalysisRun
    {
        return AnalysisRun::query()
            ->where('source_snapshot_id', $sourceSnapshotId)
            ->where('result_type', $resultType->value)
            ->whereIn('status', [AnalysisRunStatus::Queued->value, AnalysisRunStatus::Running->value, AnalysisRunStatus::Succeeded->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function dispatch(AnalysisRun $run): void
    {
        try {
            $this->dispatcher->dispatch(new AnalyzeSourceSnapshot($run->id));
            Log::info('analysis.queued', $this->context($run));
        } catch (Throwable $e) {
            $run->markFailed(AnalysisFailure::DispatchFailed->value, AnalysisFailure::DispatchFailed->message());
            Log::error('analysis.failed', $this->context($run) + ['error_code' => AnalysisFailure::DispatchFailed->value, 'exception' => $e::class]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(AnalysisRun $run): array
    {
        return [
            'analysis_run_id' => $run->id,
            'project_id' => $run->project_id,
            'source_snapshot_id' => $run->source_snapshot_id,
            'result_type' => $run->result_type->value,
            'status' => $run->status->value,
        ];
    }
}
