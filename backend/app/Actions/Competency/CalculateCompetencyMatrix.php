<?php

declare(strict_types=1);

namespace App\Actions\Competency;

use App\Enums\Competency\CompetencyFailure;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Services\Competency\CompetencyEngine;
use App\Services\Competency\CompetencyException;
use App\Services\Competency\CompetencySpecification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates the immutable competency snapshot of one DNA snapshot for the
 * configured competency version (docs/architecture/competency-matrix-v1.md#calculation).
 *
 * The only input is the DNA snapshot ID, chosen by the server. Evidence,
 * ownership and lineage come from the stored DNA snapshot; the only other
 * read is the list of languages the analysis measured (the keys of the
 * stored result's metrics.by_language, read in SQL without loading the
 * result). Nothing is re-analyzed or re-scored. In one transaction:
 *
 * 1. lock the DNA snapshot row;
 * 2. return the existing competency snapshot for (DNA snapshot, version);
 * 3. assess and insert.
 *
 * Idempotent: the row lock serializes callers and
 * UNIQUE (dna_snapshot_id, competency_version) backs it up.
 */
final readonly class CalculateCompetencyMatrix
{
    public function __construct(
        private ConnectionInterface $db,
        private CompetencyEngine $engine,
        private Repository $config,
    ) {}

    /**
     * @throws CompetencyException
     */
    public function handle(string $dnaSnapshotId): CalculatedCompetencyMatrix
    {
        $spec = CompetencySpecification::forVersion((string) $this->config->get('codedna.competency.version'));

        try {
            return $this->db->transaction(fn (): CalculatedCompetencyMatrix => $this->calculate($dnaSnapshotId, $spec));
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race the row lock did not prevent: the other snapshot stands.
            $existing = $this->existing($dnaSnapshotId, $spec->version) ?? throw $e;

            return new CalculatedCompetencyMatrix($existing, false);
        }
    }

    private function calculate(string $dnaSnapshotId, CompetencySpecification $spec): CalculatedCompetencyMatrix
    {
        $dna = DnaSnapshot::query()->whereKey($dnaSnapshotId)->lockForUpdate()->first()
            ?? throw new CompetencyException(CompetencyFailure::DnaSnapshotNotFound);

        $existing = $this->existing($dna->id, $spec->version);
        if ($existing !== null) {
            return new CalculatedCompetencyMatrix($existing, false);
        }
        if (! in_array($dna->scoring_version, $spec->dnaScoringVersions, true)) {
            throw new CompetencyException(CompetencyFailure::DnaScoringVersionUnsupported);
        }

        $languages = $this->languages($dna->analysis_run_id);
        $evidence = $dna->evidence ?? [];
        $result = $this->engine->assess($dna->dimensions, $evidence, $dna->scoring_version, $languages ?? [], $spec);

        $snapshot = new CompetencySnapshot;
        $snapshot->forceFill([
            'user_id' => $dna->user_id,
            'project_id' => $dna->project_id,
            'dna_snapshot_id' => $dna->id,
            'analysis_run_id' => $dna->analysis_run_id,
            'source_snapshot_id' => $dna->source_snapshot_id,
            'competency_version' => $spec->version,
            'dna_scoring_version' => $dna->scoring_version,
            'specification_fingerprint' => $spec->fingerprint(),
            'status' => $result->status,
            'competencies' => $result->competencies,
            'summary' => $result->summary,
            'provenance' => [
                'competency_version' => $spec->version,
                'specification_fingerprint' => $spec->fingerprint(),
                'dna_snapshot_id' => $dna->id,
                'dna_scoring_version' => $dna->scoring_version,
                'dna_specification_fingerprint' => is_string($evidence['specification_fingerprint'] ?? null) ? $evidence['specification_fingerprint'] : null,
                'dna_status' => $dna->status->value,
                'dna_overall_score' => $dna->overall_score,
                'dna_data_quality' => $dna->data_quality,
                'analysis_run_id' => $dna->analysis_run_id,
                'source_snapshot_id' => $dna->source_snapshot_id,
                'result_hash' => $dna->result_hash,
                'metrics_version' => $dna->metrics_version,
                // null: the run has no stored result to read the languages from.
                'languages' => $languages,
            ],
        ]);
        $snapshot->save();

        return new CalculatedCompetencyMatrix($snapshot, true);
    }

    /**
     * Languages measured by the run (sorted), or null without a stored result.
     *
     * @return list<string>|null
     */
    private function languages(string $analysisRunId): ?array
    {
        if (! $this->db->table('analysis_results')->where('analysis_run_id', $analysisRunId)->exists()) {
            return null;
        }

        return array_map('strval', $this->db->table('analysis_results')
            ->crossJoin($this->db->raw(<<<'SQL'
                LATERAL jsonb_object_keys(
                    CASE WHEN jsonb_typeof(analysis_results.result->'metrics'->'by_language') = 'object'
                        THEN analysis_results.result->'metrics'->'by_language' ELSE '{}'::jsonb END
                ) AS language
            SQL))
            ->where('analysis_results.analysis_run_id', $analysisRunId)
            ->orderBy('language')
            ->pluck('language')
            ->all());
    }

    private function existing(string $dnaSnapshotId, string $version): ?CompetencySnapshot
    {
        return CompetencySnapshot::query()
            ->where('dna_snapshot_id', $dnaSnapshotId)
            ->where('competency_version', $version)
            ->first();
    }
}
