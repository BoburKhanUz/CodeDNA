<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Analysis\PersistAnalysisResult;
use App\Actions\Dna\CalculateDnaSnapshot;
use App\Enums\AnalysisFailure;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Models\AnalysisRun;
use App\Models\SourceSnapshot;
use App\Services\Analyzer\AnalyzerClient;
use App\Services\Analyzer\AnalyzerException;
use App\Services\Dna\DnaScoringException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one analysis attempt for an analysis run
 * (docs/architecture/data-flow.md#queue-job). The payload is the run ID
 * only: no URL, no source, no secret.
 *
 * Each execution:
 *
 * 1. claims the run in a transaction holding the run's row lock: a QUEUED
 *    run becomes RUNNING; a RUNNING run is only resumed by the job that
 *    holds its lease (same claim token), or by any job once that lease has
 *    expired (a crashed worker). A terminal run, or a run leased by another
 *    live job, is left alone: duplicate jobs do nothing;
 * 2. calls the analyzer with a fresh pre-signed URL and a new request ID
 *    (AnalyzerClient verifies everything it gets back);
 * 3. persists the verified result (PersistAnalysisResult) and, for a
 *    static_analysis result, scores it (CalculateDnaSnapshot, Phase 11), or
 * 4. on a retryable failure releases itself with backoff while attempts
 *    remain (the run stays RUNNING), otherwise marks the run FAILED with a
 *    safe failure code.
 *
 * Attempts are counted in the run's metadata, so the bound holds across
 * releases, redeliveries and duplicate jobs.
 */
final class AnalyzeSourceSnapshot implements ShouldQueue
{
    use Queueable;

    /** Laravel's own bound; the run's attempt count is the authoritative one. */
    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * Identifies this dispatched job in the run's lease. Serialized with the
     * job, so it survives releases and redeliveries; a second dispatch for
     * the same run gets its own token and cannot take over a live lease.
     */
    public readonly string $claimToken;

    public function __construct(public readonly string $analysisRunId)
    {
        $this->claimToken = (string) Str::uuid();
        $this->onConnection((string) config('codedna.analysis.queue_connection'));
        $this->onQueue((string) config('codedna.analysis.queue'));
        $this->tries = (int) config('codedna.analyzer.max_attempts');
        $this->timeout = (int) config('codedna.analysis.job_timeout_seconds');
    }

    public function handle(AnalyzerClient $client, PersistAnalysisResult $persist, ConnectionInterface $db, CalculateDnaSnapshot $score): void
    {
        $claim = $db->transaction(fn (): ?array => $this->claim());
        if ($claim === null) {
            return;
        }
        /** @var AnalysisRun $run */
        [$run, $attempt, $requestId] = $claim;
        $snapshot = SourceSnapshot::query()->findOrFail($run->source_snapshot_id);
        $context = $this->context($run) + ['attempt' => $attempt, 'request_id' => $requestId];
        Log::info('analysis.started', $context);
        $started = microtime(true);

        try {
            $result = $client->analyze($run, $snapshot, $attempt, $requestId);
        } catch (AnalyzerException $e) {
            $this->handleFailure($db, $e, $attempt, $context + ['duration_ms' => $this->elapsed($started)]);

            return;
        } catch (Throwable $e) {
            $this->failRun($db, AnalysisFailure::AnalysisFailed, $context + ['duration_ms' => $this->elapsed($started), 'exception' => $e::class]);

            return;
        }

        $stored = $persist->handle($run->id, $result);
        Log::info($stored ? 'analysis.completed' : 'analysis.result_ignored', $context + [
            'duration_ms' => $this->elapsed($started),
            'status' => $stored ? AnalysisRunStatus::Succeeded->value : 'unchanged',
            'result_hash' => $result->resultHash,
            'replayed' => $result->replayed,
        ]);

        if ($stored && $run->result_type === AnalysisResultType::StaticAnalysis) {
            $this->score($score, $run);
        }
    }

    /**
     * Scores the run once its result is stored. Best effort: the run is
     * already SUCCEEDED and stays so; a scoring failure is logged and the
     * run can be scored later (php artisan dna:score).
     */
    private function score(CalculateDnaSnapshot $score, AnalysisRun $run): void
    {
        $context = $this->context($run) + ['scoring_version' => (string) config('codedna.scoring.version')];

        try {
            $calculated = $score->handle($run->id);
            Log::info('dna.scored', $context + [
                'dna_snapshot_id' => $calculated->snapshot->id,
                'status' => $calculated->snapshot->status->value,
                'created' => $calculated->created,
            ]);
        } catch (DnaScoringException $e) {
            Log::warning('dna.scoring_failed', $context + ['error_code' => $e->failure->value]);
        } catch (Throwable $e) {
            Log::error('dna.scoring_failed', $context + ['exception' => $e::class]);
        }
    }

    /**
     * Called by Laravel when the job dies (worker timeout, attempts exceeded):
     * the run must not stay RUNNING.
     */
    public function failed(?Throwable $e): void
    {
        $failure = $e instanceof TimeoutExceededException || $e instanceof MaxAttemptsExceededException
            ? AnalysisFailure::AnalyzerTimeout
            : AnalysisFailure::AnalysisFailed;

        $this->failRun(app(ConnectionInterface::class), $failure, [
            'analysis_run_id' => $this->analysisRunId,
            'exception' => $e === null ? null : $e::class,
        ]);
    }

    /**
     * @return array{0: AnalysisRun, 1: int, 2: string}|null run, attempt number and analyzer request ID
     */
    private function claim(): ?array
    {
        $run = AnalysisRun::query()->whereKey($this->analysisRunId)->lockForUpdate()->first();
        if ($run === null || $run->status->isTerminal()) {
            return null;
        }

        $now = Carbon::now();
        $jobId = $this->claimToken;
        $metadata = $run->metadata ?? [];
        $lease = $metadata['lease'] ?? null;
        if ($run->status === AnalysisRunStatus::Running && is_array($lease)
            && ($lease['job'] ?? null) !== $jobId && Carbon::parse((string) $lease['expires_at'])->isFuture()) {
            Log::info('analysis.duplicate_job_skipped', $this->context($run));

            return null;
        }

        $attempts = array_values((array) ($metadata['attempts'] ?? []));
        $attempt = count($attempts) + 1;
        if ($attempt > (int) config('codedna.analyzer.max_attempts')) {
            // Attempts already used up (e.g. redeliveries after crashes).
            $this->markFailed($run, AnalysisFailure::AnalyzerUnavailable);

            return null;
        }

        $requestId = (string) Str::uuid();
        $attempts[] = ['attempt' => $attempt, 'request_id' => $requestId, 'started_at' => $now->toIso8601ZuluString()];
        $metadata['attempts'] = $attempts;
        $metadata['lease'] = [
            'job' => $jobId,
            'expires_at' => $now->copy()->addSeconds($this->timeout + 30)->toIso8601ZuluString(),
        ];
        $run->metadata = $metadata;

        if ($run->status === AnalysisRunStatus::Queued) {
            $run->markRunning();
        } else {
            $run->save();
        }

        return [$run, $attempt, $requestId];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleFailure(ConnectionInterface $db, AnalyzerException $e, int $attempt, array $context): void
    {
        $context += ['error_code' => $e->failure->value, 'http_status' => $e->httpStatus, 'analyzer_code' => $e->analyzerCode, 'reason' => $e->getMessage()];
        $maxAttempts = (int) config('codedna.analyzer.max_attempts');

        if (! $e->retryable || $attempt >= $maxAttempts) {
            $this->failRun($db, $e->failure, $context + ['retryable' => $e->retryable]);

            return;
        }

        $delay = $this->delayFor($e, $attempt);
        $released = $db->transaction(function () use ($e, $delay): bool {
            $run = AnalysisRun::query()->whereKey($this->analysisRunId)->lockForUpdate()->first();
            if ($run === null || $run->status !== AnalysisRunStatus::Running) {
                return false;
            }
            $metadata = $run->metadata ?? [];
            $attempts = array_values((array) ($metadata['attempts'] ?? []));
            $last = count($attempts) - 1;
            if ($last >= 0) {
                $attempts[$last]['error_code'] = $e->failure->value;
            }
            $metadata['attempts'] = $attempts;
            // Keep the lease while waiting, so no other job takes the run over.
            $metadata['lease']['expires_at'] = Carbon::now()->addSeconds($delay + $this->timeout + 30)->toIso8601ZuluString();
            $run->metadata = $metadata;
            $run->save();

            return true;
        });

        if ($released) {
            Log::warning('analysis.retrying', $context + ['retry_in_seconds' => $delay]);
            $this->release($delay);
        }
    }

    private function delayFor(AnalyzerException $e, int $attempt): int
    {
        // An expired source URL is fixed by the fresh URL of the next attempt.
        if ($e->failure === AnalysisFailure::SourceUrlExpired) {
            return 1;
        }
        $backoff = array_values((array) config('codedna.analyzer.backoff_seconds'));
        $delay = (int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 30);

        return max($delay, min((int) $e->retryAfterSeconds, 600));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failRun(ConnectionInterface $db, AnalysisFailure $failure, array $context): void
    {
        $failed = $db->transaction(function () use ($failure): bool {
            $run = AnalysisRun::query()->whereKey($this->analysisRunId)->lockForUpdate()->first();
            if ($run === null || $run->status->isTerminal()) {
                return false;
            }
            $this->markFailed($run, $failure);

            return true;
        });

        if ($failed) {
            Log::warning('analysis.failed', $context + ['status' => AnalysisRunStatus::Failed->value, 'error_code' => $failure->value]);
        }
    }

    private function markFailed(AnalysisRun $run, AnalysisFailure $failure): void
    {
        $metadata = $run->metadata ?? [];
        unset($metadata['lease']);
        $run->metadata = $metadata === [] ? null : $metadata;
        $run->markFailed($failure->value, $failure->message());
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
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
