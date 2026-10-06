<?php

declare(strict_types=1);

namespace Tests\Unit\Dna;

use App\Enums\Dna\DnaDimension;
use App\Services\Dna\FixedPoint;
use App\Services\Dna\ScoringSpecification;
use App\Services\Dna\Specification\ComponentSpec;
use App\Services\Dna\Specification\DimensionSpec;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ScoringSpecificationTest extends TestCase
{
    /**
     * The definition of 1.0.0 is frozen. If this fails, the change needs a
     * new scoring version (and documentation), not a new fingerprint here.
     */
    public function test_version_1_0_0_is_frozen(): void
    {
        $spec = ScoringSpecification::forVersion('1.0.0');

        $this->assertSame('1.0.0', $spec->version);
        $this->assertSame(['1.0.0'], ScoringSpecification::VERSIONS);
        $this->assertSame('c07bd65575b0423973e072eaa55b6d28bb6a46e52290945646810983ba02763b', $spec->fingerprint());
    }

    public function test_version_1_0_0_weights_and_thresholds(): void
    {
        $spec = ScoringSpecification::v1_0_0();
        $array = $spec->toArray();

        $weights = array_column($array['dimensions'], 'weight', 'id');
        $this->assertSame(['COMPLEXITY' => '0.4000', 'STRUCTURE' => '0.4000', 'CODE_HYGIENE' => '0.2000'], $weights);
        $this->assertSame(FixedPoint::ONE, array_sum(array_map(fn (DimensionSpec $d): int => $d->weight, $spec->dimensions)));
        foreach ($spec->dimensions as $dimension) {
            $this->assertSame(FixedPoint::ONE, array_sum(array_map(fn (ComponentSpec $c): int => $c->weight, $dimension->components)));
        }
        $this->assertSame('static_analysis', $spec->resultType);
        $this->assertSame(['1.0'], $spec->metricsVersions);
        $this->assertSame(2, $spec->minimumScoredDimensions);

        $thresholds = [];
        foreach ($array['dimensions'] as $dimension) {
            foreach ($dimension['components'] as $component) {
                $thresholds[$component['key']] = [$component['weight'], $component['best'], $component['worst'], $component['minimum_denominator'], $component['required']];
            }
        }
        $this->assertSame([
            'mean_cyclomatic_complexity' => ['0.5000', '2.0000', '10.0000', 5, true],
            'complex_function_share' => ['0.2500', '0.0000', '0.2000', 5, true],
            'deep_nesting_share' => ['0.2500', '0.0000', '0.2000', 5, true],
            'long_function_share' => ['0.4000', '0.0000', '0.1000', 5, true],
            'long_parameter_list_share' => ['0.3000', '0.0000', '0.2000', 5, true],
            'large_type_share' => ['0.3000', '0.0000', '0.2000', 3, false],
            'syntax_error_share' => ['1.0000', '0.0000', '0.2500', 3, true],
        ], $thresholds);
        $this->assertSame(['0.5000', '0.2500', '0.2500', 50], [
            $array['data_quality']['parse_coverage_weight'],
            $array['data_quality']['evidence_volume_weight'],
            $array['data_quality']['metric_availability_weight'],
            $array['data_quality']['evidence_volume_target'],
        ]);
    }

    public function test_every_input_is_a_count_of_the_static_analysis_contract(): void
    {
        $allowed = '/^(metrics\.overall\.(functions_total|complexity_total|complexity_over_threshold|types|files_parsed|files_parse_error|files_analyzable)'
            .'|findings\.by_rule\.(structure\/nesting-depth|structure\/function-length|structure\/parameter-count|structure\/class-length))$/';
        foreach (ScoringSpecification::v1_0_0()->dimensions as $dimension) {
            foreach ($dimension->components as $component) {
                foreach ([...$component->numerator, ...$component->denominator] as $path) {
                    $this->assertMatchesRegularExpression($allowed, $path);
                }
            }
        }
    }

    public function test_unknown_versions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScoringSpecification::forVersion('1.0');
    }

    public function test_invalid_specifications_cannot_be_constructed(): void
    {
        $base = ScoringSpecification::v1_0_0();
        $component = fn (int $weight, int $best = 0, int $worst = 2_000): ComponentSpec => new ComponentSpec(
            'c', 'test', $weight, ['metrics.overall.types'], ['metrics.overall.types'], $best, $worst, 1, true, true,
        );
        $dimension = fn (DnaDimension $d, int $weight, ?ComponentSpec $c = null): DimensionSpec => new DimensionSpec($d, 'test', $weight, [$c ?? $component(FixedPoint::ONE)]);

        $invalid = [
            'weights below 1' => [[$dimension(DnaDimension::Complexity, 5_000), $dimension(DnaDimension::Structure, 4_999)], 1],
            'weights above 1' => [[$dimension(DnaDimension::Complexity, 6_000), $dimension(DnaDimension::Structure, 4_001)], 1],
            'zero weight' => [[$dimension(DnaDimension::Complexity, 10_000), $dimension(DnaDimension::Structure, 0)], 1],
            'duplicate dimension' => [[$dimension(DnaDimension::Complexity, 5_000), $dimension(DnaDimension::Complexity, 5_000)], 1],
            'component weights' => [[$dimension(DnaDimension::Complexity, 10_000, $component(9_000))], 1],
            'best not below worst' => [[$dimension(DnaDimension::Complexity, 10_000, $component(10_000, 2_000, 2_000))], 1],
            'minimum scored dimensions' => [[$dimension(DnaDimension::Complexity, 10_000)], 2],
        ];
        foreach ($invalid as $name => [$dimensions, $minimum]) {
            try {
                new ScoringSpecification('9.9.9', 'static_analysis', ['1.0'], $minimum, $dimensions, $base->dataQuality);
                $this->fail("accepted: {$name}");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        new ScoringSpecification('1.0', 'static_analysis', ['1.0'], 1, [$dimension(DnaDimension::Complexity, 10_000)], $base->dataQuality);
    }
}
