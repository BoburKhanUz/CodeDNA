<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Services\Analyzer\CanonicalJson;
use App\Services\Dna\FixedPoint;
use InvalidArgumentException;

/**
 * The deterministic growth rules (docs/architecture/growth-tracking-v1.md#rules).
 * Versioned and fingerprinted: any change needs a new version, and stored
 * growth snapshots are never reinterpreted.
 *
 * All arithmetic is integer fixed-point with 4 decimal places (FixedPoint),
 * the precision of every stored score and gap.
 */
final readonly class GrowthRules
{
    public const VERSION_1_0_0 = '1.0.0';

    /** @var list<string> */
    public const VERSIONS = [self::VERSION_1_0_0];

    /** The assessment versions and specifications that must be equal to compare two assessments. */
    public const COMPATIBILITY = [
        'dna_scoring_version', 'dna_specification_fingerprint', 'metrics_version',
        'competency_version', 'competency_specification_fingerprint',
        'skill_gap_version', 'skill_gap_specification_fingerprint', 'target_profile', 'target_profile_version',
    ];

    /**
     * @param  int  $meaningfulDelta  units (1/10000): a change of at least this much, in either direction, is meaningful
     * @param  int  $minimumEvidenceQuality  units: below this on either side, no change is claimed
     * @param  array<string, list<string>>  $measuredStates  per metric type, the states that carry a comparable value
     */
    public function __construct(
        public string $version,
        public int $meaningfulDelta,
        public int $minimumEvidenceQuality,
        public array $measuredStates,
    ) {}

    public static function forVersion(string $version): self
    {
        return match ($version) {
            self::VERSION_1_0_0 => self::v1_0_0(),
            default => throw new InvalidArgumentException("Unknown growth rules version: {$version}"),
        };
    }

    /**
     * Rules 1.0.0: a change of at least 0.0500 is meaningful (the same
     * resolution as Phase 14's material-gap threshold); no change is
     * claimed when either side's evidence quality is below 0.6000 (Phase 14's
     * bound for a HIGH priority).
     */
    public static function v1_0_0(): self
    {
        return new self(
            version: self::VERSION_1_0_0,
            meaningfulDelta: FixedPoint::parse('0.0500'),
            minimumEvidenceQuality: FixedPoint::parse('0.6000'),
            measuredStates: [
                'DNA' => ['SCORED', 'READY'],
                'COMPETENCY' => ['ASSESSED'],
                'SKILL_GAP' => ['GAP', 'NO_GAP'],
            ],
        );
    }

    /**
     * @return array<string, mixed> the complete definition, in a fixed order
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'arithmetic' => 'integer fixed-point, 4 decimal places',
            'baseline' => 'the immediately preceding assessment of the same project, by analysis run completion',
            'compatibility' => self::COMPATIBILITY,
            'meaningful_delta' => FixedPoint::format($this->meaningfulDelta),
            'meaningful_delta_inclusive' => true,
            'minimum_evidence_quality' => FixedPoint::format($this->minimumEvidenceQuality),
            'measured_states' => $this->measuredStates,
            'better_direction' => ['DNA' => 'higher', 'COMPETENCY' => 'higher', 'SKILL_GAP' => 'lower'],
            'gap_transitions' => ['GAP->NO_GAP' => 'IMPROVED', 'NO_GAP->GAP' => 'REGRESSED'],
            'level_order' => ['NOT_ESTABLISHED', 'DEVELOPING', 'ESTABLISHED', 'STRONG'],
        ];
    }

    public function fingerprint(): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode($this->toArray(), JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
    }
}
