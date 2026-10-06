<?php

declare(strict_types=1);

namespace App\Services\Competency;

use App\Enums\Competency\CompetencyKey;
use App\Enums\Competency\CompetencyLevel;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Dna\FixedPoint;
use InvalidArgumentException;
use LogicException;

/**
 * The authoritative definition of every competency version
 * (docs/architecture/competency-matrix-v1.md): competencies, their evidence
 * mapping onto DNA components, weights, level boundaries and the
 * evidence-quality formula. The engine has no constants of its own.
 *
 * A published version never changes meaning: any change to a competency,
 * mapping, weight, boundary or formula is a new version. The fingerprint of
 * 1.0.0 is pinned by a test.
 */
final readonly class CompetencySpecification
{
    public const VERSION_1_0_0 = '1.0.0';

    /** Every competency version this code can compute. */
    public const VERSIONS = [self::VERSION_1_0_0];

    /**
     * @param  list<string>  $dnaScoringVersions  DNA scoring versions whose evidence structure this version reads
     * @param  list<CompetencyDefinition>  $competencies
     * @param  array<string, int>  $levels  level value => lowest score (units) of the level, ascending, first at 0
     * @param  array{parse_coverage: int, evidence_volume: int, evidence_availability: int}  $evidenceQualityWeights
     */
    public function __construct(
        public string $version,
        public array $dnaScoringVersions,
        public array $competencies,
        public array $levels,
        public array $evidenceQualityWeights,
    ) {
        $this->validate();
    }

    public static function forVersion(string $version): self
    {
        return match ($version) {
            self::VERSION_1_0_0 => self::v1_0_0(),
            default => throw new InvalidArgumentException("Unknown competency version: {$version}"),
        };
    }

    /**
     * Competency version 1.0.0, built on DNA scoring 1.0.0.
     *
     * Each of the seven DNA 1.0.0 components is used by exactly one
     * competency (no double counting); the competencies regroup them by what
     * they say about the code rather than repeat the DNA dimensions.
     * Module or package organization (CODE_STRUCTURE) has no supporting
     * evidence in metrics 1.0 and is not defined.
     *
     * Level boundaries are v1 calibration choices on the 0–1 component-score
     * scale, not empirical norms.
     */
    public static function v1_0_0(): self
    {
        $u = FixedPoint::parse(...);
        $cpp = 'Parsed without a preprocessor: decisions hidden in macros are not counted and #if branches are all parsed.';

        return new self(
            version: self::VERSION_1_0_0,
            dnaScoringVersions: ['1.0.0'],
            competencies: [
                new CompetencyDefinition(
                    key: CompetencyKey::ComplexityManagement,
                    description: 'Keeping decision logic per function small: how much branching (cyclomatic complexity) functions contain.',
                    evidence: [
                        new EvidenceRule('COMPLEXITY', 'mean_cyclomatic_complexity', $u('0.60'), true,
                            'Average branching per function: the central measure of decision logic.'),
                        new EvidenceRule('COMPLEXITY', 'complex_function_share', $u('0.40'), true,
                            'How many functions exceed the complexity threshold: concentration of branching.'),
                    ],
                    partialLanguages: ['c' => $cpp, 'cpp' => $cpp],
                ),
                new CompetencyDefinition(
                    key: CompetencyKey::FunctionDesign,
                    description: 'Shaping functions so they stay short, take few parameters and keep control flow shallow.',
                    evidence: [
                        new EvidenceRule('STRUCTURE', 'long_function_share', $u('0.40'), true,
                            'Function length: functions over the length threshold.'),
                        new EvidenceRule('STRUCTURE', 'long_parameter_list_share', $u('0.30'), true,
                            'Function interface width: functions over the parameter threshold.'),
                        new EvidenceRule('COMPLEXITY', 'deep_nesting_share', $u('0.30'), true,
                            'Function body shape: functions nested deeper than the nesting threshold.'),
                    ],
                    partialLanguages: ['c' => $cpp, 'cpp' => $cpp],
                ),
                new CompetencyDefinition(
                    key: CompetencyKey::TypeStructure,
                    description: 'Keeping types (classes, interfaces, structs, traits, enums) within a manageable size.',
                    evidence: [
                        new EvidenceRule('STRUCTURE', 'large_type_share', $u('1'), true,
                            'Types over the type-length threshold. Requires at least 3 types; code without types is not assessed.'),
                    ],
                    partialLanguages: [],
                ),
                new CompetencyDefinition(
                    key: CompetencyKey::CodeHygiene,
                    description: 'Keeping analyzable source files syntactically valid. A direct mapping of the CODE_HYGIENE dimension, kept as a competency because syntax validity is distinct from design.',
                    evidence: [
                        new EvidenceRule('CODE_HYGIENE', 'syntax_error_share', $u('1'), true,
                            'Share of parsed-or-failed files with syntax errors.'),
                    ],
                    partialLanguages: ['c' => $cpp, 'cpp' => $cpp],
                ),
            ],
            levels: [
                CompetencyLevel::NotEstablished->value => $u('0'),
                CompetencyLevel::Developing->value => $u('0.40'),
                CompetencyLevel::Established->value => $u('0.65'),
                CompetencyLevel::Strong->value => $u('0.85'),
            ],
            evidenceQualityWeights: [
                'parse_coverage' => $u('0.50'),
                'evidence_volume' => $u('0.25'),
                'evidence_availability' => $u('0.25'),
            ],
        );
    }

    /**
     * The complete definition as plain data, in a fixed order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $format = FixedPoint::format(...);

        return [
            'version' => $this->version,
            'dna_scoring_versions' => $this->dnaScoringVersions,
            'arithmetic' => 'integer fixed-point, 4 decimal places, round half up at every division',
            'score' => 'weighted mean of the scores of the available evidence components; weights renormalized over available ones',
            'competencies' => array_map(fn (CompetencyDefinition $c): array => [
                'key' => $c->key->value,
                'name' => $c->key->displayName(),
                'description' => $c->description,
                'evidence' => array_map(fn (EvidenceRule $e): array => [
                    'source' => $e->source(),
                    'weight' => $format($e->weight),
                    'required' => $e->required,
                    'rationale' => $e->rationale,
                ], $c->evidence),
                'partial_languages' => $c->partialLanguages,
            ], $this->competencies),
            'levels' => array_map($format, $this->levels),
            'evidence_quality_weights' => array_map($format, $this->evidenceQualityWeights),
        ];
    }

    /**
     * SHA-256 of the canonical JSON of toArray().
     */
    public function fingerprint(): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode($this->toArray(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * The level of an assessed score: the highest level whose lower bound
     * the score reaches.
     */
    public function levelFor(int $score): CompetencyLevel
    {
        $level = null;
        foreach ($this->levels as $value => $minimum) {
            if ($score >= $minimum) {
                $level = CompetencyLevel::from($value);
            }
        }

        return $level ?? throw new LogicException('Scores are never negative.');
    }

    private function validate(): void
    {
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $this->version) !== 1) {
            throw new LogicException('A competency version is MAJOR.MINOR.PATCH.');
        }
        if ($this->dnaScoringVersions === [] || $this->competencies === []) {
            throw new LogicException('A competency version needs DNA scoring versions and competencies.');
        }

        $keys = [];
        $sources = [];
        foreach ($this->competencies as $competency) {
            $keys[] = $competency->key->value;
            if ($competency->evidence === [] || array_filter($competency->evidence, fn (EvidenceRule $e): bool => $e->required) === []) {
                throw new LogicException("Competency {$competency->key->value} needs required evidence.");
            }
            $weights = array_map(fn (EvidenceRule $e): int => $e->weight, $competency->evidence);
            if (min($weights) <= 0 || array_sum($weights) !== FixedPoint::ONE) {
                throw new LogicException("The evidence weights of {$competency->key->value} must be positive and sum to exactly 1.");
            }
            foreach ($competency->evidence as $rule) {
                $sources[] = $rule->source();
            }
        }
        if (count(array_unique($keys)) !== count($keys)) {
            throw new LogicException('Competencies must be unique.');
        }
        // No double counting: each DNA component is evidence of at most one competency.
        if (count(array_unique($sources)) !== count($sources)) {
            throw new LogicException('A DNA component may be evidence of only one competency.');
        }

        $levels = array_keys($this->levels);
        if ($levels !== array_map(fn (CompetencyLevel $l): string => $l->value, CompetencyLevel::cases())) {
            throw new LogicException('Every level must be defined, lowest first.');
        }
        $bounds = array_values($this->levels);
        if ($bounds[0] !== 0 || max($bounds) > FixedPoint::ONE) {
            throw new LogicException('Level bounds start at 0 and stay within 1.');
        }
        for ($i = 1; $i < count($bounds); $i++) {
            if ($bounds[$i] <= $bounds[$i - 1]) {
                throw new LogicException('Level bounds must strictly increase.');
            }
        }

        $quality = $this->evidenceQualityWeights;
        if (array_keys($quality) !== ['parse_coverage', 'evidence_volume', 'evidence_availability']
            || min($quality) <= 0 || array_sum($quality) !== FixedPoint::ONE) {
            throw new LogicException('Evidence-quality weights must be positive and sum to exactly 1.');
        }
    }
}
