<?php

declare(strict_types=1);

namespace App\Services\Dna;

use App\Enums\DnaSnapshotStatus;

/**
 * The output of CodeDnaScoringEngine for one result and one scoring
 * version. Scores are decimal strings with 4 places ("0.8125"), never floats.
 */
final readonly class DnaScore
{
    /**
     * @param  string|null  $overallScore  null unless READY
     * @param  array<string, array<string, mixed>>  $dimensions  keyed by dimension identifier, in specification order
     * @param  array<string, mixed>  $calculation  aggregation, availability summary and data-quality breakdown
     */
    public function __construct(
        public DnaSnapshotStatus $status,
        public ?string $overallScore,
        public string $dataQuality,
        public array $dimensions,
        public array $calculation,
    ) {}
}
