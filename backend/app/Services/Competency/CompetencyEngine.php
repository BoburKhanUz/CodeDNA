<?php

declare(strict_types=1);

namespace App\Services\Competency;

use App\Enums\Competency\CompetencyFailure;
use App\Enums\Competency\CompetencyLevel;
use App\Enums\Competency\CompetencySnapshotStatus;
use App\Enums\Competency\CompetencyStatus;
use App\Enums\Dna\EvidenceStatus;
use App\Services\Dna\FixedPoint;
use InvalidArgumentException;

/**
 * Derives the competency matrix from a stored DNA snapshot
 * (docs/architecture/competency-matrix-v1.md). A pure function of (DNA
 * dimensions and evidence, measured languages, specification): no clock,
 * randomness, database, network or AI, and no floating point.
 *
 * It reads the per-component evidence the scoring engine stored (status,
 * normalized score, raw counts); it never re-normalizes raw counts and never
 * uses a dimension score. Competencies are processed in the specification's
 * order.
 */
final class CompetencyEngine
{
    private const STATUS_PRECEDENCE = [EvidenceStatus::Unsupported, EvidenceStatus::Missing, EvidenceStatus::InsufficientEvidence];

    /**
     * @param  array<array-key, mixed>  $dimensions  DnaSnapshot::$dimensions
     * @param  array<array-key, mixed>  $dnaEvidence  DnaSnapshot::$evidence
     * @param  list<string>  $languages  languages measured by the analysis
     *
     * @throws CompetencyException
     */
    public function assess(array $dimensions, array $dnaEvidence, string $dnaScoringVersion, array $languages, CompetencySpecification $spec): CompetencyResult
    {
        if (! in_array($dnaScoringVersion, $spec->dnaScoringVersions, true)) {
            throw new CompetencyException(CompetencyFailure::DnaScoringVersionUnsupported);
        }
        $parseCoverage = $this->decimal($dnaEvidence['data_quality']['parse_coverage']['value'] ?? null);
        $evidenceVolume = $this->decimal($dnaEvidence['data_quality']['evidence_volume']['value'] ?? null);
        sort($languages);

        $competencies = [];
        foreach ($spec->competencies as $definition) {
            $competencies[] = $this->competency($definition, $dimensions, $parseCoverage, $evidenceVolume, $languages, $spec);
        }

        $statuses = array_fill_keys(array_map(fn (CompetencyStatus $s): string => $s->value, CompetencyStatus::cases()), 0);
        $levels = array_fill_keys(array_map(fn (CompetencyLevel $l): string => $l->value, CompetencyLevel::cases()), 0);
        foreach ($competencies as $competency) {
            $statuses[$competency['status']]++;
            if ($competency['level'] !== null) {
                $levels[$competency['level']]++;
            }
        }

        return new CompetencyResult(
            status: $statuses[CompetencyStatus::Assessed->value] > 0 ? CompetencySnapshotStatus::Assessed : CompetencySnapshotStatus::InsufficientData,
            competencies: $competencies,
            summary: ['competencies' => count($competencies), 'statuses' => $statuses, 'levels' => $levels],
            languages: array_values($languages),
        );
    }

    /**
     * @param  array<array-key, mixed>  $dimensions
     * @param  list<string>  $languages
     * @return array<string, mixed>
     */
    private function competency(CompetencyDefinition $definition, array $dimensions, int $parseCoverage, int $evidenceVolume, array $languages, CompetencySpecification $spec): array
    {
        $evidence = [];
        $failing = [];
        $weightedSum = 0;
        $availableWeight = 0;
        $available = 0;

        foreach ($definition->evidence as $rule) {
            [$status, $entry, $score] = $this->evidence($rule, $dimensions);
            $evidence[] = $entry;
            if ($status === EvidenceStatus::Available) {
                $available++;
                $weightedSum = FixedPoint::add($weightedSum, FixedPoint::multiply((int) $score, $rule->weight));
                $availableWeight += $rule->weight;
            } elseif ($rule->required) {
                $failing[] = $status;
            }
        }

        $status = CompetencyStatus::Assessed;
        foreach (self::STATUS_PRECEDENCE as $candidate) {
            if (in_array($candidate, $failing, true)) {
                $status = CompetencyStatus::from($candidate->value);
                break;
            }
        }
        $score = $status === CompetencyStatus::Assessed ? FixedPoint::divide($weightedSum, $availableWeight) : null;

        $weights = $spec->evidenceQualityWeights;
        $availability = FixedPoint::divide(FixedPoint::multiply($available, FixedPoint::ONE), count($definition->evidence));
        $quality = FixedPoint::divide(
            FixedPoint::multiply($parseCoverage, $weights['parse_coverage'])
            + FixedPoint::multiply($evidenceVolume, $weights['evidence_volume'])
            + FixedPoint::multiply($availability, $weights['evidence_availability']),
            FixedPoint::ONE,
        );

        $limitations = [];
        foreach ($languages as $language) {
            if (isset($definition->partialLanguages[$language])) {
                $limitations[] = ['language' => $language, 'note' => $definition->partialLanguages[$language]];
            }
        }

        return [
            'key' => $definition->key->value,
            'name' => $definition->key->displayName(),
            'status' => $status->value,
            'score' => $score === null ? null : FixedPoint::format($score),
            'level' => $score === null ? null : $spec->levelFor($score)->value,
            'evidence_quality' => FixedPoint::format($quality),
            'evidence_quality_terms' => [
                'parse_coverage' => FixedPoint::format($parseCoverage),
                'evidence_volume' => FixedPoint::format($evidenceVolume),
                'evidence_availability' => FixedPoint::format($availability),
            ],
            'evidence' => $evidence,
            'limitations' => $limitations,
        ];
    }

    /**
     * One DNA component as evidence: its stored status, normalized score and
     * raw counts, passed through.
     *
     * @param  array<array-key, mixed>  $dimensions
     * @return array{0: EvidenceStatus, 1: array<string, mixed>, 2: int|null}
     */
    private function evidence(EvidenceRule $rule, array $dimensions): array
    {
        $component = $dimensions[$rule->dimension]['components'][$rule->component] ?? null;
        $entry = [
            'source' => $rule->source(),
            'dimension' => $rule->dimension,
            'component' => $rule->component,
            'weight' => FixedPoint::format($rule->weight),
            'required' => $rule->required,
            'status' => EvidenceStatus::Missing->value,
            'value' => null,
            'score' => null,
            'best' => null,
            'worst' => null,
            'minimum_denominator' => null,
            'numerator' => [],
            'denominator' => [],
        ];
        if ($component === null) {
            return [EvidenceStatus::Missing, $entry, null];
        }
        if (! is_array($component)) {
            throw new CompetencyException(CompetencyFailure::DnaSnapshotInvalid);
        }

        $status = EvidenceStatus::tryFrom(is_string($component['status'] ?? null) ? $component['status'] : '')
            ?? throw new CompetencyException(CompetencyFailure::DnaSnapshotInvalid);
        $score = $status === EvidenceStatus::Available ? $this->decimal($component['score'] ?? null) : null;

        $entry = [
            ...$entry,
            'status' => $status->value,
            'value' => is_string($component['value'] ?? null) ? $component['value'] : null,
            'score' => $score === null ? null : FixedPoint::format($score),
            'best' => is_string($component['best'] ?? null) ? $component['best'] : null,
            'worst' => is_string($component['worst'] ?? null) ? $component['worst'] : null,
            'minimum_denominator' => is_int($component['minimum_denominator'] ?? null) ? $component['minimum_denominator'] : null,
            'numerator' => $this->counts($component['numerator'] ?? []),
            'denominator' => $this->counts($component['denominator'] ?? []),
        ];

        return [$status, $entry, $score];
    }

    /**
     * Metric path => raw count, sorted by path.
     *
     * @return array<string, int|null>
     */
    private function counts(mixed $counts): array
    {
        if (! is_array($counts)) {
            throw new CompetencyException(CompetencyFailure::DnaSnapshotInvalid);
        }
        $result = [];
        foreach ($counts as $path => $value) {
            $result[(string) $path] = is_int($value) ? $value : null;
        }
        ksort($result);

        return $result;
    }

    /**
     * A stored 0–1 decimal string in units.
     */
    private function decimal(mixed $value): int
    {
        try {
            $units = is_string($value) ? FixedPoint::parse($value) : -1;
        } catch (InvalidArgumentException) {
            $units = -1;
        }
        if ($units < 0 || $units > FixedPoint::ONE) {
            throw new CompetencyException(CompetencyFailure::DnaSnapshotInvalid);
        }

        return $units;
    }
}
