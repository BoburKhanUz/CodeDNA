<?php

declare(strict_types=1);

namespace App\Services\Dna\Specification;

/**
 * The data_quality formula (docs/architecture/dna-scoring-v1.md#data-quality):
 *
 *     data_quality = parse_coverage_weight × files_parsed / files_analyzable
 *                  + evidence_volume_weight × min(functions_total / evidence_volume_target, 1)
 *                  + metric_availability_weight × available components / all components
 *
 * Objective facts about the input only: not a confidence, not a probability.
 */
final readonly class DataQualitySpec
{
    public function __construct(
        public int $parseCoverageWeight,
        public int $evidenceVolumeWeight,
        public int $metricAvailabilityWeight,
        public string $parsedFiles,
        public string $analyzableFiles,
        public string $evidenceVolume,
        public int $evidenceVolumeTarget,
    ) {}
}
