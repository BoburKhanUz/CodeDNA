<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SkillGap\CalculateSkillGapSnapshot;
use App\Models\CompetencySnapshot;
use App\Services\SkillGap\SkillGapException;
use App\Services\SkillGap\SkillGapSpecification;
use Illuminate\Console\Command;

/**
 * Operator command: analyzes skill gaps of competency snapshots with the
 * configured skill gap version and its target profile
 * (docs/architecture/skill-gap-v1.md#calculation). Used for competency
 * snapshots created before Phase 14, after a failure, or after a version
 * change. Idempotent: snapshots already analyzed are skipped.
 */
final class CalculateSkillGaps extends Command
{
    protected $signature = 'skill-gap:calculate
        {competency_snapshot? : ID of one competency snapshot}
        {--missing : Analyze every competency snapshot of a supported competency version without a skill gap snapshot for this version}';

    protected $description = 'Create skill gap snapshots from competency snapshots';

    public function handle(CalculateSkillGapSnapshot $calculate): int
    {
        $competencyId = $this->argument('competency_snapshot');
        $version = (string) config('codedna.skill_gap.version');
        if (is_string($competencyId) === (bool) $this->option('missing')) {
            $this->error('Pass either a competency snapshot ID or --missing.');

            return self::INVALID;
        }

        $spec = SkillGapSpecification::forVersion($version);
        $ids = is_string($competencyId) ? [$competencyId] : CompetencySnapshot::query()
            ->whereIn('competency_version', $spec->competencyVersions)
            ->whereNotExists(fn ($query) => $query->from('skill_gap_snapshots')
                ->whereColumn('skill_gap_snapshots.competency_snapshot_id', 'competency_snapshots.id')
                ->where('skill_gap_snapshots.skill_gap_version', $spec->version)
                ->where('skill_gap_snapshots.target_profile', $spec->targetProfile->key))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $calculated = $calculate->handle((string) $id);
                $this->line(sprintf('%s %s %s', $id, $calculated->created ? 'created' : 'exists', $calculated->snapshot->status->value));
            } catch (SkillGapException $e) {
                $failed++;
                $this->line("{$id} failed {$e->failure->value}");
            }
        }
        $this->info(sprintf('Skill gap version %s (%s): %d competency snapshot(s), %d failed.', $version, $spec->targetProfile->key, count($ids), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
