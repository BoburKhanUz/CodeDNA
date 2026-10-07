<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Models\SkillGapSnapshot;
use App\Services\Growth\GrowthException;
use Illuminate\Console\Command;

/**
 * Operator command: creates growth snapshots of assessments (skill gap
 * snapshots) with the configured growth rules
 * (docs/architecture/growth-tracking-v1.md#generation). Used for
 * assessments created before Phase 18, after a failure, or after a rules
 * version change. Idempotent: assessments already tracked are skipped.
 * --missing processes assessments oldest first, by analysis run
 * completion, the same order the baseline uses.
 */
final class CalculateGrowth extends Command
{
    protected $signature = 'growth:calculate
        {skill_gap_snapshot? : ID of one skill gap snapshot}
        {--missing : Track every assessment without a growth snapshot for the configured rules version}';

    protected $description = 'Create growth snapshots from skill gap snapshots';

    public function handle(CalculateGrowthSnapshot $calculate): int
    {
        $skillGapId = $this->argument('skill_gap_snapshot');
        $version = (string) config('codedna.growth.rules_version');
        if (is_string($skillGapId) === (bool) $this->option('missing')) {
            $this->error('Pass either a skill gap snapshot ID or --missing.');

            return self::INVALID;
        }

        $ids = is_string($skillGapId) ? [$skillGapId] : SkillGapSnapshot::query()
            ->join('analysis_runs', 'analysis_runs.id', '=', 'skill_gap_snapshots.analysis_run_id')
            ->whereNotExists(fn ($query) => $query->from('growth_snapshots')
                ->whereColumn('growth_snapshots.skill_gap_snapshot_id', 'skill_gap_snapshots.id')
                ->where('growth_snapshots.rules_version', $version))
            ->orderBy('analysis_runs.completed_at')
            ->orderBy('analysis_runs.id')
            ->orderBy('skill_gap_snapshots.id')
            ->pluck('skill_gap_snapshots.id')
            ->all();

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $calculated = $calculate->handle((string) $id);
                $this->line(sprintf('%s %s %s', $id, $calculated->created ? 'created' : 'exists', $calculated->snapshot->status->value));
            } catch (GrowthException $e) {
                $failed++;
                $this->line("{$id} failed {$e->failure->value}");
            }
        }
        $this->info(sprintf('Growth rules %s: %d assessment(s), %d failed.', $version, count($ids), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
