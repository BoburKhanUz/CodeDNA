<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Competency\CalculateCompetencyMatrix;
use App\Models\DnaSnapshot;
use App\Services\Competency\CompetencyException;
use App\Services\Competency\CompetencySpecification;
use Illuminate\Console\Command;

/**
 * Operator command: derives competency matrices from DNA snapshots with the
 * configured competency version (docs/architecture/competency-matrix-v1.md#calculation).
 * Used for DNA snapshots created before Phase 13, after a failure, or after
 * a version change. Idempotent: snapshots already assessed are skipped.
 */
final class CalculateCompetencies extends Command
{
    protected $signature = 'competency:calculate
        {dna_snapshot? : ID of one DNA snapshot}
        {--missing : Assess every DNA snapshot of a supported scoring version without a competency snapshot for this version}';

    protected $description = 'Create competency snapshots from DNA snapshots';

    public function handle(CalculateCompetencyMatrix $calculate): int
    {
        $dnaId = $this->argument('dna_snapshot');
        $version = (string) config('codedna.competency.version');
        if (is_string($dnaId) === (bool) $this->option('missing')) {
            $this->error('Pass either a DNA snapshot ID or --missing.');

            return self::INVALID;
        }

        $ids = is_string($dnaId) ? [$dnaId] : DnaSnapshot::query()
            ->whereIn('scoring_version', CompetencySpecification::forVersion($version)->dnaScoringVersions)
            ->whereNotExists(fn ($query) => $query->from('competency_snapshots')
                ->whereColumn('competency_snapshots.dna_snapshot_id', 'dna_snapshots.id')
                ->where('competency_snapshots.competency_version', $version))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $calculated = $calculate->handle((string) $id);
                $this->line(sprintf('%s %s %s', $id, $calculated->created ? 'created' : 'exists', $calculated->snapshot->status->value));
            } catch (CompetencyException $e) {
                $failed++;
                $this->line("{$id} failed {$e->failure->value}");
            }
        }
        $this->info(sprintf('Competency version %s: %d DNA snapshot(s), %d failed.', $version, count($ids), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
