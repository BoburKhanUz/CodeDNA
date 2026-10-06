<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The versions that make an analysis result reproducible and comparable
 * (ADR-004 §2). Recorded on successful analysis runs and their DNA snapshots.
 * metrics and scoring are null when the result does not have them (a
 * foundation result has neither; no result has scoring before Phase 11).
 */
final readonly class AnalysisVersions
{
    public function __construct(
        public string $analyzer,
        public string $ir,
        public ?string $metrics,
        public ?string $scoring,
        public string $contract,
    ) {}

    /**
     * @return array{analyzer_version: string, ir_version: string, metrics_version: string|null, scoring_version: string|null, contract_version: string}
     */
    public function toAttributes(): array
    {
        return [
            'analyzer_version' => $this->analyzer,
            'ir_version' => $this->ir,
            'metrics_version' => $this->metrics,
            'scoring_version' => $this->scoring,
            'contract_version' => $this->contract,
        ];
    }
}
