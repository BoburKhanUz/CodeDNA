<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Dna\CalculateDnaSnapshot;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Models\AnalysisRun;
use App\Services\Dna\DnaScoringException;
use Illuminate\Console\Command;

/**
 * Operator command: scores SUCCEEDED static_analysis runs with the
 * configured scoring version (docs/architecture/dna-scoring-v1.md#calculation).
 * Used for runs that succeeded before Phase 11 or whose automatic scoring
 * failed. Idempotent: runs already scored with this version are skipped.
 */
final class ScoreAnalysisRuns extends Command
{
    protected $signature = 'dna:score
        {analysis_run? : ID of one analysis run}
        {--missing : Score every SUCCEEDED static_analysis run without a snapshot for this scoring version}';

    protected $description = 'Create DNA snapshots for successful static analysis runs';

    public function handle(CalculateDnaSnapshot $calculate): int
    {
        $runId = $this->argument('analysis_run');
        $version = (string) config('codedna.scoring.version');
        if (is_string($runId) === (bool) $this->option('missing')) {
            $this->error('Pass either an analysis run ID or --missing.');

            return self::INVALID;
        }

        $ids = is_string($runId) ? [$runId] : AnalysisRun::query()
            ->where('status', AnalysisRunStatus::Succeeded->value)
            ->where('result_type', AnalysisResultType::StaticAnalysis->value)
            ->whereDoesntHave('dnaSnapshots', fn ($query) => $query->where('scoring_version', $version))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $calculated = $calculate->handle((string) $id);
                $this->line(sprintf('%s %s %s', $id, $calculated->created ? 'created' : 'exists', $calculated->snapshot->status->value));
            } catch (DnaScoringException $e) {
                $failed++;
                $this->line("{$id} failed {$e->failure->value}");
            }
        }
        $this->info(sprintf('Scoring version %s: %d run(s), %d failed.', $version, count($ids), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
