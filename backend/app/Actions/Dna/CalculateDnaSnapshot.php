<?php

declare(strict_types=1);

namespace App\Actions\Dna;

use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Enums\Dna\DnaScoringFailure;
use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Models\DnaSnapshot;
use App\Models\Project;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Dna\CodeDnaScoringEngine;
use App\Services\Dna\DnaScoringException;
use App\Services\Dna\ScoringSpecification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use stdClass;

/**
 * Creates the immutable DNA snapshot of one analysis run for the configured
 * scoring version (docs/architecture/dna-scoring-v1.md#calculation).
 *
 * The only input is the run ID, chosen by the server: the result, its hash,
 * the owner and every weight and threshold come from the database and the
 * scoring specification, never from a client. In one transaction:
 *
 * 1. lock the run; only a SUCCEEDED run with a verified static_analysis
 *    result of a supported metrics version is scored;
 * 2. return the existing snapshot for (run, scoring version) if there is one;
 * 3. re-verify the stored result against the run's result_hash (the
 *    persisted result is the only source: no analyzer call, no source file);
 * 4. score it and insert the snapshot.
 *
 * Idempotent: the run lock serializes callers and the
 * UNIQUE (analysis_run_id, scoring_version) constraint backs it up, so
 * concurrent calls yield one snapshot.
 */
final readonly class CalculateDnaSnapshot
{
    /** Sections excluded from result_hash (internal analyzer contract, section 4). */
    private const UNHASHED_FIELDS = ['request_id', 'diagnostics', 'result_hash'];

    public function __construct(
        private ConnectionInterface $db,
        private CodeDnaScoringEngine $engine,
        private Repository $config,
    ) {}

    /**
     * @throws DnaScoringException
     */
    public function handle(string $analysisRunId): CalculatedDnaSnapshot
    {
        $spec = ScoringSpecification::forVersion((string) $this->config->get('codedna.scoring.version'));

        try {
            return $this->db->transaction(fn (): CalculatedDnaSnapshot => $this->calculate($analysisRunId, $spec));
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race the run lock did not prevent: the other snapshot stands.
            $existing = $this->existing($analysisRunId, $spec->version) ?? throw $e;

            return new CalculatedDnaSnapshot($existing, false);
        }
    }

    private function calculate(string $analysisRunId, ScoringSpecification $spec): CalculatedDnaSnapshot
    {
        $run = AnalysisRun::query()->whereKey($analysisRunId)->lockForUpdate()->first()
            ?? throw new DnaScoringException(DnaScoringFailure::RunNotFound);
        if ($run->status !== AnalysisRunStatus::Succeeded || $run->result_hash === null) {
            throw new DnaScoringException(DnaScoringFailure::RunNotSucceeded);
        }

        $existing = $this->existing($run->id, $spec->version);
        if ($existing !== null) {
            return new CalculatedDnaSnapshot($existing, false);
        }

        $stored = AnalysisResult::query()->find($run->id) ?? throw new DnaScoringException(DnaScoringFailure::ResultMissing);
        if ($stored->result_type !== AnalysisResultType::StaticAnalysis || $run->result_type !== AnalysisResultType::StaticAnalysis
            || $stored->result_type->value !== $spec->resultType) {
            throw new DnaScoringException(DnaScoringFailure::ResultTypeNotScoreable);
        }
        $result = $this->verified($run, $stored);
        $metricsVersion = $result->metrics->version ?? null;
        if (! in_array($metricsVersion, $spec->metricsVersions, true) || $stored->metrics_version !== $metricsVersion) {
            throw new DnaScoringException(DnaScoringFailure::MetricsVersionUnsupported);
        }

        $score = $this->engine->score($result, $spec);

        $snapshot = new DnaSnapshot;
        $snapshot->forceFill([
            'user_id' => Project::query()->whereKey($run->project_id)->value('user_id'),
            'project_id' => $run->project_id,
            'analysis_run_id' => $run->id,
            'source_snapshot_id' => $run->source_snapshot_id,
            'analyzer_version' => $stored->analyzer_version,
            'ir_version' => $stored->ir_version,
            'metrics_version' => $stored->metrics_version,
            'scoring_version' => $spec->version,
            'contract_version' => $stored->contract_version,
            'status' => $score->status,
            'overall_score' => $score->overallScore,
            'data_quality' => $score->dataQuality,
            'dimensions' => $score->dimensions,
            // Interpretation (competencies, strengths, weaknesses) is not part of scoring.
            'competencies' => null,
            'strengths' => null,
            'weaknesses' => null,
            'evidence' => [
                'scoring_version' => $spec->version,
                'specification_fingerprint' => $spec->fingerprint(),
                'source' => [
                    'analysis_run_id' => $run->id,
                    'source_snapshot_id' => $run->source_snapshot_id,
                    'result_type' => $stored->result_type->value,
                    'result_hash' => $stored->result_hash,
                    'metrics_version' => $stored->metrics_version,
                ],
                ...$score->calculation,
            ],
            'result_hash' => $run->result_hash,
        ]);
        $snapshot->save();

        return new CalculatedDnaSnapshot($snapshot, true);
    }

    /**
     * The stored result, after checking it still hashes to the run's
     * result_hash and belongs to the run.
     */
    private function verified(AnalysisRun $run, AnalysisResult $stored): stdClass
    {
        if (! hash_equals((string) $run->result_hash, $stored->result_hash)) {
            throw new DnaScoringException(DnaScoringFailure::ResultIntegrityFailed);
        }
        $result = $stored->decoded();
        $hashed = new stdClass;
        foreach (get_object_vars($result) as $key => $value) {
            if (! in_array($key, self::UNHASHED_FIELDS, true)) {
                $hashed->{$key} = $value;
            }
        }
        if (! hash_equals($stored->result_hash, CanonicalJson::hash($hashed))
            || strtolower((string) ($result->analysis_run_id ?? '')) !== strtolower($run->id)
            || ($result->result_type ?? null) !== $stored->result_type->value) {
            throw new DnaScoringException(DnaScoringFailure::ResultIntegrityFailed);
        }

        return $result;
    }

    private function existing(string $analysisRunId, string $scoringVersion): ?DnaSnapshot
    {
        return DnaSnapshot::query()
            ->where('analysis_run_id', $analysisRunId)
            ->where('scoring_version', $scoringVersion)
            ->first();
    }
}
