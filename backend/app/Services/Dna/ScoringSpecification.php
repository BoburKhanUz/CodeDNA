<?php

declare(strict_types=1);

namespace App\Services\Dna;

use App\Enums\Dna\DnaDimension;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Dna\Specification\ComponentSpec;
use App\Services\Dna\Specification\DataQualitySpec;
use App\Services\Dna\Specification\DimensionSpec;
use InvalidArgumentException;
use LogicException;

/**
 * The authoritative definition of every CodeDNA scoring version
 * (docs/architecture/dna-scoring-v1.md). Everything that decides a score
 * lives here: inputs, dimensions, weights, thresholds, minimum evidence,
 * aggregation and the data-quality formula. The engine has no constants of
 * its own.
 *
 * A published version never changes meaning: any change to a weight,
 * threshold, input, formula or rounding rule is a new version. The
 * fingerprint of 1.0.0 is pinned by a test so a silent change fails CI.
 */
final readonly class ScoringSpecification
{
    public const VERSION_1_0_0 = '1.0.0';

    /** Every scoring version this code can compute. */
    public const VERSIONS = [self::VERSION_1_0_0];

    /**
     * @param  list<string>  $metricsVersions  analyzer metrics versions this version can score
     * @param  int  $minimumScoredDimensions  fewer scored dimensions = INSUFFICIENT_DATA, no overall score
     * @param  list<DimensionSpec>  $dimensions
     */
    public function __construct(
        public string $version,
        public string $resultType,
        public array $metricsVersions,
        public int $minimumScoredDimensions,
        public array $dimensions,
        public DataQualitySpec $dataQuality,
    ) {
        $this->validate();
    }

    public static function forVersion(string $version): self
    {
        return match ($version) {
            self::VERSION_1_0_0 => self::v1_0_0(),
            default => throw new InvalidArgumentException("Unknown CodeDNA scoring version: {$version}"),
        };
    }

    /**
     * Scoring version 1.0.0.
     *
     * Inputs are only integer counts from metrics 1.0 (`metrics.overall`)
     * and rule set 1.0 (`findings.by_rule`, which always counts every
     * finding, also when `findings.items` is truncated). Averages reported
     * by the analyzer are not used: ratios are recomputed here from the
     * integer counts, exactly.
     *
     * Thresholds are v1 calibration choices, not empirical norms. Each
     * "worst" is the point at which a component scores 0; finding-based
     * shares use the analyzer's own per-function thresholds (complexity
     * > 10, nesting > 4, > 100 lines, > 5 parameters, types > 500 lines).
     */
    public static function v1_0_0(): self
    {
        $u = FixedPoint::parse(...);
        $functions = ['metrics.overall.functions_total'];

        return new self(
            version: self::VERSION_1_0_0,
            resultType: 'static_analysis',
            metricsVersions: ['1.0'],
            minimumScoredDimensions: 2,
            dimensions: [
                new DimensionSpec(
                    dimension: DnaDimension::Complexity,
                    description: 'How much branching the functions contain, from cyclomatic complexity and nesting.',
                    weight: $u('0.40'),
                    components: [
                        new ComponentSpec(
                            key: 'mean_cyclomatic_complexity',
                            description: 'complexity_total / functions_total: average cyclomatic complexity per function (1 is the minimum).',
                            weight: $u('0.50'),
                            numerator: ['metrics.overall.complexity_total'],
                            denominator: $functions,
                            best: $u('2'),
                            worst: $u('10'),
                            minimumDenominator: 5,
                            share: false,
                            required: true,
                        ),
                        new ComponentSpec(
                            key: 'complex_function_share',
                            description: 'Share of functions with cyclomatic complexity above 10.',
                            weight: $u('0.25'),
                            numerator: ['metrics.overall.complexity_over_threshold'],
                            denominator: $functions,
                            best: $u('0'),
                            worst: $u('0.20'),
                            minimumDenominator: 5,
                            share: true,
                            required: true,
                        ),
                        new ComponentSpec(
                            key: 'deep_nesting_share',
                            description: 'Share of functions with control-flow nesting deeper than 4.',
                            weight: $u('0.25'),
                            numerator: ['findings.by_rule.structure/nesting-depth'],
                            denominator: $functions,
                            best: $u('0'),
                            worst: $u('0.20'),
                            minimumDenominator: 5,
                            share: true,
                            required: true,
                        ),
                    ],
                ),
                new DimensionSpec(
                    dimension: DnaDimension::Structure,
                    description: 'How large functions, parameter lists and types are.',
                    weight: $u('0.40'),
                    components: [
                        new ComponentSpec(
                            key: 'long_function_share',
                            description: 'Share of functions longer than 100 lines.',
                            weight: $u('0.40'),
                            numerator: ['findings.by_rule.structure/function-length'],
                            denominator: $functions,
                            best: $u('0'),
                            worst: $u('0.10'),
                            minimumDenominator: 5,
                            share: true,
                            required: true,
                        ),
                        new ComponentSpec(
                            key: 'long_parameter_list_share',
                            description: 'Share of functions with more than 5 parameters.',
                            weight: $u('0.30'),
                            numerator: ['findings.by_rule.structure/parameter-count'],
                            denominator: $functions,
                            best: $u('0'),
                            worst: $u('0.20'),
                            minimumDenominator: 5,
                            share: true,
                            required: true,
                        ),
                        new ComponentSpec(
                            key: 'large_type_share',
                            description: 'Share of types (classes, interfaces, structs, ...) longer than 500 lines.',
                            weight: $u('0.30'),
                            numerator: ['findings.by_rule.structure/class-length'],
                            denominator: ['metrics.overall.types'],
                            best: $u('0'),
                            worst: $u('0.20'),
                            minimumDenominator: 3,
                            share: true,
                            required: false,
                        ),
                    ],
                ),
                new DimensionSpec(
                    dimension: DnaDimension::CodeHygiene,
                    description: 'Whether the analyzable files parse without syntax errors.',
                    weight: $u('0.20'),
                    components: [
                        new ComponentSpec(
                            key: 'syntax_error_share',
                            description: 'files_parse_error / (files_parsed + files_parse_error): share of parsed-or-failed files with syntax errors.',
                            weight: $u('1'),
                            numerator: ['metrics.overall.files_parse_error'],
                            denominator: ['metrics.overall.files_parsed', 'metrics.overall.files_parse_error'],
                            best: $u('0'),
                            worst: $u('0.25'),
                            minimumDenominator: 3,
                            share: true,
                            required: true,
                        ),
                    ],
                ),
            ],
            dataQuality: new DataQualitySpec(
                parseCoverageWeight: $u('0.50'),
                evidenceVolumeWeight: $u('0.25'),
                metricAvailabilityWeight: $u('0.25'),
                parsedFiles: 'metrics.overall.files_parsed',
                analyzableFiles: 'metrics.overall.files_analyzable',
                evidenceVolume: 'metrics.overall.functions_total',
                evidenceVolumeTarget: 50,
            ),
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
            'result_type' => $this->resultType,
            'metrics_versions' => $this->metricsVersions,
            'minimum_scored_dimensions' => $this->minimumScoredDimensions,
            'arithmetic' => 'integer fixed-point, 4 decimal places, round half up at every division',
            'aggregation' => 'weighted mean of SCORED dimensions; weights renormalized over SCORED dimensions',
            'dimensions' => array_map(fn (DimensionSpec $d): array => [
                'id' => $d->dimension->value,
                'name' => $d->dimension->displayName(),
                'description' => $d->description,
                'weight' => $format($d->weight),
                'components' => array_map(fn (ComponentSpec $c): array => [
                    'key' => $c->key,
                    'description' => $c->description,
                    'weight' => $format($c->weight),
                    'numerator' => $c->numerator,
                    'denominator' => $c->denominator,
                    'best' => $format($c->best),
                    'worst' => $format($c->worst),
                    'minimum_denominator' => $c->minimumDenominator,
                    'share' => $c->share,
                    'required' => $c->required,
                ], $d->components),
            ], $this->dimensions),
            'data_quality' => [
                'parse_coverage_weight' => $format($this->dataQuality->parseCoverageWeight),
                'evidence_volume_weight' => $format($this->dataQuality->evidenceVolumeWeight),
                'metric_availability_weight' => $format($this->dataQuality->metricAvailabilityWeight),
                'parsed_files' => $this->dataQuality->parsedFiles,
                'analyzable_files' => $this->dataQuality->analyzableFiles,
                'evidence_volume' => $this->dataQuality->evidenceVolume,
                'evidence_volume_target' => $this->dataQuality->evidenceVolumeTarget,
            ],
        ];
    }

    /**
     * SHA-256 of the canonical JSON of toArray(): identifies the exact
     * definition a snapshot was computed with.
     */
    public function fingerprint(): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode($this->toArray(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
    }

    private function validate(): void
    {
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $this->version) !== 1) {
            throw new LogicException('A scoring version is MAJOR.MINOR.PATCH.');
        }
        if ($this->dimensions === [] || $this->minimumScoredDimensions < 1 || $this->minimumScoredDimensions > count($this->dimensions)) {
            throw new LogicException('Invalid minimum number of scored dimensions.');
        }
        $this->assertWeights(array_map(fn (DimensionSpec $d): int => $d->weight, $this->dimensions), 'dimension');

        $ids = [];
        foreach ($this->dimensions as $dimension) {
            $ids[] = $dimension->dimension->value;
            $this->assertWeights(array_map(fn (ComponentSpec $c): int => $c->weight, $dimension->components), $dimension->dimension->value);
            $keys = array_map(fn (ComponentSpec $c): string => $c->key, $dimension->components);
            if (count(array_unique($keys)) !== count($keys)) {
                throw new LogicException('Component keys must be unique within a dimension.');
            }
            foreach ($dimension->components as $component) {
                if ($component->best < 0 || $component->best >= $component->worst || $component->minimumDenominator < 1
                    || $component->numerator === [] || $component->denominator === []) {
                    throw new LogicException("Invalid component {$component->key}.");
                }
            }
            if (array_filter($dimension->components, fn (ComponentSpec $c): bool => $c->required) === []) {
                throw new LogicException('A dimension needs at least one required component.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new LogicException('Dimensions must be unique.');
        }

        $quality = $this->dataQuality;
        $this->assertWeights([$quality->parseCoverageWeight, $quality->evidenceVolumeWeight, $quality->metricAvailabilityWeight], 'data quality');
        if ($quality->evidenceVolumeTarget < 1) {
            throw new LogicException('The evidence volume target must be positive.');
        }
    }

    /**
     * @param  list<int>  $weights
     */
    private function assertWeights(array $weights, string $what): void
    {
        foreach ($weights as $weight) {
            if ($weight <= 0) {
                throw new LogicException("Every {$what} weight must be positive.");
            }
        }
        if (array_sum($weights) !== FixedPoint::ONE) {
            throw new LogicException("The {$what} weights must sum to exactly 1.");
        }
    }
}
