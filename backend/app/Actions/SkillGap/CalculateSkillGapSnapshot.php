<?php

declare(strict_types=1);

namespace App\Actions\SkillGap;

use App\Enums\SkillGap\SkillGapFailure;
use App\Models\CompetencySnapshot;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use App\Services\Competency\CompetencySpecification;
use App\Services\SkillGap\SkillGapEngine;
use App\Services\SkillGap\SkillGapException;
use App\Services\SkillGap\SkillGapSpecification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Creates the immutable skill gap snapshot of one competency snapshot for
 * the configured skill gap version and its target profile
 * (docs/architecture/skill-gap-v1.md#calculation).
 *
 * The only input is the competency snapshot ID, chosen by the server;
 * scores come from the stored competency snapshot, targets from the
 * specification. Nothing is re-analyzed, re-scored or re-assessed. In one
 * transaction:
 *
 * 1. lock the competency snapshot row;
 * 2. return the existing snapshot for (competency snapshot, version, profile);
 * 3. check the competency version is supported and the snapshot was
 *    produced by exactly that version's definition (fingerprint);
 * 4. analyze and insert the snapshot and its per-competency results.
 *
 * Idempotent: the row lock serializes callers and
 * UNIQUE (competency_snapshot_id, skill_gap_version, target_profile) backs it up.
 */
final readonly class CalculateSkillGapSnapshot
{
    public function __construct(
        private ConnectionInterface $db,
        private SkillGapEngine $engine,
        private Repository $config,
    ) {}

    /**
     * @throws SkillGapException
     */
    public function handle(string $competencySnapshotId): CalculatedSkillGapSnapshot
    {
        $spec = SkillGapSpecification::forVersion((string) $this->config->get('codedna.skill_gap.version'));

        try {
            return $this->db->transaction(fn (): CalculatedSkillGapSnapshot => $this->calculate($competencySnapshotId, $spec));
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race the row lock did not prevent: the other snapshot stands.
            $existing = $this->existing($competencySnapshotId, $spec) ?? throw $e;

            return new CalculatedSkillGapSnapshot($existing, false);
        }
    }

    private function calculate(string $competencySnapshotId, SkillGapSpecification $spec): CalculatedSkillGapSnapshot
    {
        $competency = CompetencySnapshot::query()->whereKey($competencySnapshotId)->lockForUpdate()->first()
            ?? throw new SkillGapException(SkillGapFailure::CompetencySnapshotNotFound);

        $existing = $this->existing($competency->id, $spec);
        if ($existing !== null) {
            return new CalculatedSkillGapSnapshot($existing, false);
        }
        if (! in_array($competency->competency_version, $spec->competencyVersions, true)) {
            throw new SkillGapException(SkillGapFailure::CompetencyVersionUnsupported);
        }
        try {
            $expected = CompetencySpecification::forVersion($competency->competency_version)->fingerprint();
        } catch (InvalidArgumentException) {
            throw new SkillGapException(SkillGapFailure::CompetencyVersionUnsupported);
        }
        if (! hash_equals($expected, $competency->specification_fingerprint)) {
            throw new SkillGapException(SkillGapFailure::CompetencySnapshotInvalid);
        }

        $analysis = $this->engine->analyze($competency->competencies, $spec);
        $competencyProvenance = $competency->provenance ?? [];

        $snapshot = new SkillGapSnapshot;
        $snapshot->forceFill([
            'user_id' => $competency->user_id,
            'project_id' => $competency->project_id,
            'competency_snapshot_id' => $competency->id,
            'dna_snapshot_id' => $competency->dna_snapshot_id,
            'analysis_run_id' => $competency->analysis_run_id,
            'source_snapshot_id' => $competency->source_snapshot_id,
            'skill_gap_version' => $spec->version,
            'target_profile' => $spec->targetProfile->key,
            'target_profile_version' => $spec->targetProfile->version,
            'competency_version' => $competency->competency_version,
            'dna_scoring_version' => $competency->dna_scoring_version,
            'specification_fingerprint' => $spec->fingerprint(),
            'status' => $analysis->status,
            'summary' => $analysis->summary,
            'provenance' => [
                'skill_gap_version' => $spec->version,
                'specification_fingerprint' => $spec->fingerprint(),
                'target_profile' => $spec->targetProfile->key,
                'target_profile_version' => $spec->targetProfile->version,
                'competency_snapshot_id' => $competency->id,
                'competency_version' => $competency->competency_version,
                'competency_specification_fingerprint' => $competency->specification_fingerprint,
                'competency_status' => $competency->status->value,
                'dna_snapshot_id' => $competency->dna_snapshot_id,
                'dna_scoring_version' => $competency->dna_scoring_version,
                'analysis_run_id' => $competency->analysis_run_id,
                'source_snapshot_id' => $competency->source_snapshot_id,
                'result_hash' => $competencyProvenance['result_hash'] ?? null,
                'languages' => $competencyProvenance['languages'] ?? null,
            ],
        ]);
        $snapshot->save();

        foreach ($analysis->results as $position => $item) {
            $result = new SkillGapResult;
            $result->forceFill([
                'skill_gap_snapshot_id' => $snapshot->id,
                'project_id' => $snapshot->project_id,
                'user_id' => $snapshot->user_id,
                'position' => $position,
                'competency_key' => $item['competency_key'],
                'status' => $item['status'],
                'competency_status' => $item['competency_status'],
                'current_score' => $item['current_score'],
                'target_score' => $item['target_score'],
                'raw_gap' => $item['raw_gap'],
                'material_gap' => $item['material_gap'],
                'priority' => $item['priority'],
                'priority_capped' => $item['priority_capped'],
                'evidence_quality' => $item['evidence_quality'],
                'current_level' => $item['current_level'],
                'evidence' => ['limitations' => $item['limitations'], 'evidence' => $item['evidence']],
            ]);
            $result->save();
        }

        return new CalculatedSkillGapSnapshot($snapshot, true);
    }

    private function existing(string $competencySnapshotId, SkillGapSpecification $spec): ?SkillGapSnapshot
    {
        return SkillGapSnapshot::query()
            ->where('competency_snapshot_id', $competencySnapshotId)
            ->where('skill_gap_version', $spec->version)
            ->where('target_profile', $spec->targetProfile->key)
            ->first();
    }
}
