<?php

declare(strict_types=1);

namespace App\Services\Dna;

use App\Enums\Dna\DimensionStatus;
use App\Enums\Dna\DnaScoringFailure;
use App\Enums\Dna\EvidenceStatus;
use App\Enums\DnaSnapshotStatus;
use App\Services\Dna\Specification\ComponentSpec;
use App\Services\Dna\Specification\DimensionSpec;
use stdClass;

/**
 * Computes a DNA score from a verified static_analysis result
 * (docs/architecture/dna-scoring-v1.md). A pure function of (result,
 * specification): no clock, no randomness, no database, no network, no
 * floating point. Dimensions and components are processed in the
 * specification's order; nothing depends on the order of the input.
 *
 * Raw metric values are evidence; they never become a score directly. Each
 * component is normalized between the specification's thresholds, each
 * dimension is the weighted mean of its available components, and the
 * overall score is the weighted mean of the SCORED dimensions with their
 * weights renormalized.
 */
final class CodeDnaScoringEngine
{
    /**
     * @throws DnaScoringException RESULT_INVALID when a used input is not a non-negative integer, or is inconsistent
     */
    public function score(stdClass $result, ScoringSpecification $spec): DnaScore
    {
        $dimensions = [];
        $scored = [];
        $availability = array_fill_keys(array_map(fn (EvidenceStatus $s): string => $s->value, EvidenceStatus::cases()), 0);

        foreach ($spec->dimensions as $dimensionSpec) {
            $dimension = $this->dimension($result, $dimensionSpec, $spec->version);
            foreach ($dimension['components'] as $component) {
                $availability[$component['status']]++;
            }
            if ($dimension['status'] === DimensionStatus::Scored->value) {
                $scored[$dimensionSpec->dimension->value] = [$dimension['units'], $dimensionSpec->weight];
            }
            $dimensions[$dimensionSpec->dimension->value] = $dimension;
        }

        $ready = count($scored) >= $spec->minimumScoredDimensions;
        $scoredWeight = array_sum(array_column($scored, 1));
        $overall = null;
        if ($ready) {
            $weightedSum = 0;
            foreach ($scored as [$units, $weight]) {
                $weightedSum = FixedPoint::add($weightedSum, FixedPoint::multiply($units, $weight));
            }
            $overall = FixedPoint::divide($weightedSum, $scoredWeight);
        }

        foreach ($dimensions as $id => &$dimension) {
            $inOverall = $ready && isset($scored[$id]);
            $dimension['effective_weight'] = $inOverall ? FixedPoint::format(FixedPoint::divide(FixedPoint::multiply($scored[$id][1], FixedPoint::ONE), $scoredWeight)) : null;
            $dimension['contribution'] = $inOverall ? FixedPoint::format(FixedPoint::divide(FixedPoint::multiply($scored[$id][0], $scored[$id][1]), $scoredWeight)) : null;
            $dimension['data_quality'] = FixedPoint::format($this->dataQuality($result, $spec, $dimension['components'])['units']);
            unset($dimension['units']);
        }
        unset($dimension);

        $allComponents = array_merge(...array_values(array_map(fn (array $d): array => array_values($d['components']), $dimensions)));
        $quality = $this->dataQuality($result, $spec, $allComponents);

        return new DnaScore(
            status: $ready ? DnaSnapshotStatus::Ready : DnaSnapshotStatus::InsufficientData,
            overallScore: $overall === null ? null : FixedPoint::format($overall),
            dataQuality: FixedPoint::format($quality['units']),
            dimensions: $dimensions,
            calculation: [
                'aggregation' => [
                    'method' => 'weighted_mean_of_scored_dimensions',
                    'minimum_scored_dimensions' => $spec->minimumScoredDimensions,
                    'scored_dimensions' => array_keys($scored),
                    'unavailable_dimensions' => array_values(array_diff(array_keys($dimensions), array_keys($scored))),
                    'scored_weight' => FixedPoint::format($scoredWeight),
                    'renormalized' => $ready && $scoredWeight !== FixedPoint::ONE,
                ],
                'availability' => $availability,
                'data_quality' => $quality['breakdown'],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dimension(stdClass $result, DimensionSpec $spec, string $version): array
    {
        $components = [];
        $weightedSum = 0;
        $availableWeight = 0;
        $reason = null;

        foreach ($spec->components as $componentSpec) {
            $component = $this->component($result, $componentSpec);
            if ($component['status'] === EvidenceStatus::Available->value) {
                $weightedSum = FixedPoint::add($weightedSum, FixedPoint::multiply($component['units'], $componentSpec->weight));
                $availableWeight += $componentSpec->weight;
            } elseif ($componentSpec->required && $reason === null) {
                $reason = $component['status'];
            }
            unset($component['units']);
            $components[$componentSpec->key] = $component;
        }

        $scored = $reason === null;
        $units = $scored ? FixedPoint::divide($weightedSum, $availableWeight) : null;

        return [
            'dimension' => $spec->dimension->value,
            'name' => $spec->dimension->displayName(),
            'scoring_version' => $version,
            'status' => ($scored ? DimensionStatus::Scored : DimensionStatus::Unavailable)->value,
            'unavailable_reason' => $reason,
            'score' => $units === null ? null : FixedPoint::format($units),
            'weight' => FixedPoint::format($spec->weight),
            'units' => $units,
            'components' => $components,
            'calculation' => [
                'method' => 'weighted_mean_of_available_components',
                'available_component_weight' => FixedPoint::format($availableWeight),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function component(stdClass $result, ComponentSpec $spec): array
    {
        [$numeratorStatus, $numerator, $numeratorEvidence] = $this->sum($result, $spec->numerator);
        [$denominatorStatus, $denominator, $denominatorEvidence] = $this->sum($result, $spec->denominator);
        $status = $this->combine($numeratorStatus, $denominatorStatus);

        $entry = [
            'status' => $status->value,
            'weight' => FixedPoint::format($spec->weight),
            'required' => $spec->required,
            'numerator' => $numeratorEvidence,
            'denominator' => $denominatorEvidence,
            'minimum_denominator' => $spec->minimumDenominator,
            'best' => FixedPoint::format($spec->best),
            'worst' => FixedPoint::format($spec->worst),
            'value' => null,
            'score' => null,
            'units' => null,
        ];
        if ($status !== EvidenceStatus::Available) {
            return $entry;
        }

        if ($spec->share && $numerator > $denominator) {
            throw new DnaScoringException(DnaScoringFailure::ResultInvalid);
        }
        if ($denominator < $spec->minimumDenominator) {
            $entry['status'] = EvidenceStatus::InsufficientEvidence->value;

            return $entry;
        }

        $units = Normalizer::lowerIsBetter($numerator, $denominator, $spec->best, $spec->worst);
        $entry['value'] = FixedPoint::format(FixedPoint::divide(FixedPoint::multiply($numerator, FixedPoint::ONE), $denominator));
        $entry['score'] = FixedPoint::format($units);
        $entry['units'] = $units;

        return $entry;
    }

    /**
     * data_quality (DataQualitySpec) for a set of components.
     *
     * @param  array<array-key, array<string, mixed>>  $components
     * @return array{units: int, breakdown: array<string, mixed>}
     */
    private function dataQuality(stdClass $result, ScoringSpecification $spec, array $components): array
    {
        $q = $spec->dataQuality;
        $parsed = $this->countOrZero($result, $q->parsedFiles);
        $analyzable = $this->countOrZero($result, $q->analyzableFiles);
        $volume = $this->countOrZero($result, $q->evidenceVolume);
        if ($parsed > $analyzable) {
            throw new DnaScoringException(DnaScoringFailure::ResultInvalid);
        }

        // No analyzable file: nothing was measured, coverage is 0, not "100% of nothing".
        $coverage = $analyzable === 0 ? 0 : FixedPoint::divide(FixedPoint::multiply($parsed, FixedPoint::ONE), $analyzable);
        $volumeUnits = FixedPoint::divide(FixedPoint::multiply(min($volume, $q->evidenceVolumeTarget), FixedPoint::ONE), $q->evidenceVolumeTarget);
        $available = count(array_filter($components, fn (array $c): bool => $c['status'] === EvidenceStatus::Available->value));
        $availability = $components === [] ? 0 : FixedPoint::divide(FixedPoint::multiply($available, FixedPoint::ONE), count($components));

        $units = FixedPoint::divide(
            FixedPoint::multiply($coverage, $q->parseCoverageWeight)
            + FixedPoint::multiply($volumeUnits, $q->evidenceVolumeWeight)
            + FixedPoint::multiply($availability, $q->metricAvailabilityWeight),
            FixedPoint::ONE,
        );

        return ['units' => $units, 'breakdown' => [
            'parse_coverage' => ['value' => FixedPoint::format($coverage), 'weight' => FixedPoint::format($q->parseCoverageWeight), $q->parsedFiles => $parsed, $q->analyzableFiles => $analyzable],
            'evidence_volume' => ['value' => FixedPoint::format($volumeUnits), 'weight' => FixedPoint::format($q->evidenceVolumeWeight), $q->evidenceVolume => $volume, 'target' => $q->evidenceVolumeTarget],
            'metric_availability' => ['value' => FixedPoint::format($availability), 'weight' => FixedPoint::format($q->metricAvailabilityWeight), 'available_components' => $available, 'components' => count($components)],
        ]];
    }

    /**
     * @param  list<string>  $paths
     * @return array{0: EvidenceStatus, 1: int, 2: array<string, int|null>}
     */
    private function sum(stdClass $result, array $paths): array
    {
        $status = EvidenceStatus::Available;
        $total = 0;
        $evidence = [];
        foreach ($paths as $path) {
            [$pathStatus, $value] = $this->read($result, $path);
            $status = $this->combine($status, $pathStatus);
            $evidence[$path] = $value;
            $total = FixedPoint::add($total, $value ?? 0);
        }

        return [$status, $total, $evidence];
    }

    private function countOrZero(stdClass $result, string $path): int
    {
        return $this->read($result, $path)[1] ?? 0;
    }

    /**
     * Reads one count. A value that is present must be a non-negative
     * integer; null is UNSUPPORTED when the metric group lists it as
     * unsupported, MISSING otherwise; an absent key is MISSING.
     *
     * @return array{0: EvidenceStatus, 1: int|null}
     */
    private function read(stdClass $result, string $path): array
    {
        $segments = explode('.', $path);
        $node = $result;
        $parent = null;
        foreach ($segments as $segment) {
            if (! $node instanceof stdClass || ! property_exists($node, $segment)) {
                return [EvidenceStatus::Missing, null];
            }
            $parent = $node;
            $node = $node->{$segment};
        }

        if ($node === null) {
            $unsupported = $parent instanceof stdClass && isset($parent->unsupported) && is_array($parent->unsupported)
                && in_array(end($segments), $parent->unsupported, true);

            return [$unsupported ? EvidenceStatus::Unsupported : EvidenceStatus::Missing, null];
        }
        if (! is_int($node) || $node < 0) {
            throw new DnaScoringException(DnaScoringFailure::ResultInvalid);
        }

        return [EvidenceStatus::Available, $node];
    }

    /**
     * UNSUPPORTED wins over MISSING, which wins over AVAILABLE.
     */
    private function combine(EvidenceStatus $a, EvidenceStatus $b): EvidenceStatus
    {
        foreach ([EvidenceStatus::Unsupported, EvidenceStatus::Missing] as $status) {
            if ($a === $status || $b === $status) {
                return $status;
            }
        }

        return EvidenceStatus::Available;
    }
}
