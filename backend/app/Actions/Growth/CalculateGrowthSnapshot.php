<?php

declare(strict_types=1);

namespace App\Actions\Growth;

use App\Enums\AnalysisRunStatus;
use App\Enums\Growth\GrowthFailure;
use App\Models\AnalysisRun;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Models\GrowthObservation;
use App\Models\GrowthSnapshot;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use App\Services\Growth\GrowthAssessment;
use App\Services\Growth\GrowthEngine;
use App\Services\Growth\GrowthException;
use App\Services\Growth\GrowthRules;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Creates the growth snapshot of one assessment (skill gap snapshot) for
 * the configured growth rules (docs/architecture/growth-tracking-v1.md#generation).
 *
 * The only input is the skill gap snapshot ID, chosen by the server. In one
 * transaction:
 *
 * 1. lock the skill gap snapshot row;
 * 2. return the existing growth snapshot for (assessment, rules version);
 * 3. find the baseline: the immediately preceding assessment of the same
 *    project, by analysis run completion (then run ID);
 * 4. read both assessments' stored values (GrowthAssessment) and compare
 *    them (GrowthEngine);
 * 5. insert the snapshot and its observations.
 *
 * Nothing is re-analyzed, re-scored or re-assessed, and no learning
 * activity is read. Idempotent: the row lock serializes callers and
 * UNIQUE (skill_gap_snapshot_id, rules_version) backs it up.
 */
final readonly class CalculateGrowthSnapshot
{
    public function __construct(
        private ConnectionInterface $db,
        private GrowthEngine $engine,
        private Repository $config,
    ) {}

    /**
     * @throws GrowthException
     */
    public function handle(string $skillGapSnapshotId): CalculatedGrowthSnapshot
    {
        $rules = GrowthRules::forVersion((string) $this->config->get('codedna.growth.rules_version'));

        try {
            return $this->db->transaction(fn (): CalculatedGrowthSnapshot => $this->calculate($skillGapSnapshotId, $rules));
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race the row lock did not prevent: the other snapshot stands.
            $existing = $this->existing($skillGapSnapshotId, $rules) ?? throw $e;

            return new CalculatedGrowthSnapshot($existing, false);
        }
    }

    private function calculate(string $skillGapSnapshotId, GrowthRules $rules): CalculatedGrowthSnapshot
    {
        $current = SkillGapSnapshot::query()->whereKey($skillGapSnapshotId)->lockForUpdate()->first()
            ?? throw new GrowthException(GrowthFailure::SkillGapSnapshotNotFound);
        $existing = $this->existing($current->id, $rules);
        if ($existing !== null) {
            return new CalculatedGrowthSnapshot($existing, false);
        }
        $run = AnalysisRun::query()->find($current->analysis_run_id);
        if ($run === null || $run->status !== AnalysisRunStatus::Succeeded || $run->completed_at === null) {
            throw new GrowthException(GrowthFailure::AssessmentIncomplete);
        }

        $previous = $this->baseline($current, $run);
        $previousRun = $previous === null ? null : AnalysisRun::query()->findOrFail($previous->analysis_run_id);
        $currentAssessment = $this->assessment($current);
        $previousAssessment = $previous === null ? null : $this->assessment($previous);
        $comparison = $this->engine->compare($previousAssessment, $currentAssessment, $rules);

        $snapshot = new GrowthSnapshot;
        $snapshot->forceFill([
            'user_id' => $current->user_id,
            'project_id' => $current->project_id,
            'skill_gap_snapshot_id' => $current->id,
            'competency_snapshot_id' => $current->competency_snapshot_id,
            'dna_snapshot_id' => $current->dna_snapshot_id,
            'analysis_run_id' => $current->analysis_run_id,
            'source_snapshot_id' => $current->source_snapshot_id,
            'assessed_at' => $run->completed_at,
            'previous_skill_gap_snapshot_id' => $previous?->id,
            'previous_competency_snapshot_id' => $previous?->competency_snapshot_id,
            'previous_dna_snapshot_id' => $previous?->dna_snapshot_id,
            'previous_analysis_run_id' => $previous?->analysis_run_id,
            'previous_source_snapshot_id' => $previous?->source_snapshot_id,
            'previous_assessed_at' => $previousRun?->completed_at,
            'rules_version' => $rules->version,
            'rules_fingerprint' => $rules->fingerprint(),
            'versions' => $currentAssessment->versions,
            'previous_versions' => $previousAssessment?->versions,
            'differences' => $comparison->differences,
            'status' => $comparison->status,
            'summary' => $comparison->summary,
        ]);
        $snapshot->save();

        foreach ($comparison->observations as $position => $observation) {
            $row = new GrowthObservation;
            $row->forceFill([
                'growth_snapshot_id' => $snapshot->id,
                'project_id' => $snapshot->project_id,
                'user_id' => $snapshot->user_id,
                'position' => $position + 1,
                ...$observation,
            ]);
            $row->save();
        }

        return new CalculatedGrowthSnapshot($snapshot, true);
    }

    /**
     * The immediately preceding assessment of the same project: the newest
     * skill gap snapshot whose analysis run completed before this one's
     * (ties by run ID). Within that run, a snapshot of the same skill gap
     * version and target profile is preferred.
     */
    private function baseline(SkillGapSnapshot $current, AnalysisRun $run): ?SkillGapSnapshot
    {
        $completed = Carbon::parse($run->completed_at);

        return SkillGapSnapshot::query()
            ->select('skill_gap_snapshots.*')
            ->join('analysis_runs', 'analysis_runs.id', '=', 'skill_gap_snapshots.analysis_run_id')
            ->where('skill_gap_snapshots.project_id', $current->project_id)
            ->where('analysis_runs.status', AnalysisRunStatus::Succeeded->value)
            ->where(fn ($query) => $query
                ->where('analysis_runs.completed_at', '<', $completed)
                ->orWhere(fn ($tie) => $tie->where('analysis_runs.completed_at', $completed)->where('analysis_runs.id', '<', $run->id)))
            ->orderByDesc('analysis_runs.completed_at')
            ->orderByDesc('analysis_runs.id')
            ->orderByRaw('CASE WHEN skill_gap_snapshots.skill_gap_version = ? AND skill_gap_snapshots.target_profile = ? THEN 0 ELSE 1 END', [
                $current->skill_gap_version, $current->target_profile,
            ])
            ->orderByDesc('skill_gap_snapshots.id')
            ->first();
    }

    private function assessment(SkillGapSnapshot $gaps): GrowthAssessment
    {
        $competency = CompetencySnapshot::query()->findOrFail($gaps->competency_snapshot_id);
        $dna = DnaSnapshot::query()->findOrFail($gaps->dna_snapshot_id);
        $results = SkillGapResult::query()->where('skill_gap_snapshot_id', $gaps->id)->orderBy('position')->get();

        return GrowthAssessment::fromSnapshots($gaps, $competency, $dna, $results);
    }

    private function existing(string $skillGapSnapshotId, GrowthRules $rules): ?GrowthSnapshot
    {
        return GrowthSnapshot::query()
            ->where('skill_gap_snapshot_id', $skillGapSnapshotId)
            ->where('rules_version', $rules->version)
            ->first();
    }
}
