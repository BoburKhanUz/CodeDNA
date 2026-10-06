<?php

declare(strict_types=1);

namespace Tests\Unit\Competency;

use App\Enums\Competency\CompetencyKey;
use App\Enums\Competency\CompetencyLevel;
use App\Services\Competency\CompetencyDefinition;
use App\Services\Competency\CompetencySpecification;
use App\Services\Competency\EvidenceRule;
use App\Services\Dna\FixedPoint;
use App\Services\Dna\ScoringSpecification;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompetencySpecificationTest extends TestCase
{
    /**
     * The definition of 1.0.0 is frozen. If this fails, the change needs a
     * new competency version (and documentation), not a new fingerprint here.
     */
    public function test_version_1_0_0_is_frozen(): void
    {
        $spec = CompetencySpecification::forVersion('1.0.0');

        $this->assertSame(['1.0.0'], CompetencySpecification::VERSIONS);
        $this->assertSame('1.0.0', $spec->version);
        $this->assertSame('f9019e29ed358920eb9e12856aecc9f07450c6466ce174b2dc191c7a64f29eac', $spec->fingerprint());
    }

    public function test_version_1_0_0_competencies_mapping_and_levels(): void
    {
        $spec = CompetencySpecification::v1_0_0();
        $array = $spec->toArray();

        $this->assertSame(['1.0.0'], $spec->dnaScoringVersions);
        $mapping = [];
        foreach ($array['competencies'] as $competency) {
            $mapping[$competency['key']] = array_map(fn (array $e): string => "{$e['source']}@{$e['weight']}", $competency['evidence']);
        }
        $this->assertSame([
            'COMPLEXITY_MANAGEMENT' => ['COMPLEXITY.mean_cyclomatic_complexity@0.6000', 'COMPLEXITY.complex_function_share@0.4000'],
            'FUNCTION_DESIGN' => ['STRUCTURE.long_function_share@0.4000', 'STRUCTURE.long_parameter_list_share@0.3000', 'COMPLEXITY.deep_nesting_share@0.3000'],
            'TYPE_STRUCTURE' => ['STRUCTURE.large_type_share@1.0000'],
            'CODE_HYGIENE' => ['CODE_HYGIENE.syntax_error_share@1.0000'],
        ], $mapping);
        $this->assertSame(['NOT_ESTABLISHED' => '0.0000', 'DEVELOPING' => '0.4000', 'ESTABLISHED' => '0.6500', 'STRONG' => '0.8500'], $array['levels']);
        $this->assertSame(['parse_coverage' => '0.5000', 'evidence_volume' => '0.2500', 'evidence_availability' => '0.2500'], $array['evidence_quality_weights']);
    }

    public function test_every_dna_component_is_used_exactly_once(): void
    {
        $dna = [];
        foreach (ScoringSpecification::v1_0_0()->dimensions as $dimension) {
            foreach ($dimension->components as $component) {
                $dna[] = "{$dimension->dimension->value}.{$component->key}";
            }
        }
        $used = [];
        foreach (CompetencySpecification::v1_0_0()->competencies as $competency) {
            foreach ($competency->evidence as $rule) {
                $used[] = $rule->source();
            }
        }
        sort($dna);
        sort($used);

        $this->assertSame($dna, $used, 'every DNA 1.0.0 component is evidence of exactly one competency');
    }

    /**
     * @return iterable<string, array{int, CompetencyLevel}>
     */
    public static function levelBoundaries(): iterable
    {
        yield 'zero' => [0, CompetencyLevel::NotEstablished];
        yield 'just below developing' => [3_999, CompetencyLevel::NotEstablished];
        yield 'exactly developing' => [4_000, CompetencyLevel::Developing];
        yield 'just above developing' => [4_001, CompetencyLevel::Developing];
        yield 'just below established' => [6_499, CompetencyLevel::Developing];
        yield 'exactly established' => [6_500, CompetencyLevel::Established];
        yield 'just above established' => [6_501, CompetencyLevel::Established];
        yield 'just below strong' => [8_499, CompetencyLevel::Established];
        yield 'exactly strong' => [8_500, CompetencyLevel::Strong];
        yield 'just above strong' => [8_501, CompetencyLevel::Strong];
        yield 'one' => [10_000, CompetencyLevel::Strong];
    }

    #[DataProvider('levelBoundaries')]
    public function test_level_boundaries(int $score, CompetencyLevel $level): void
    {
        $this->assertSame($level, CompetencySpecification::v1_0_0()->levelFor($score));
    }

    public function test_any_change_to_the_definition_changes_the_fingerprint(): void
    {
        $base = CompetencySpecification::v1_0_0();
        $levels = $base->levels;
        $levels['STRONG'] = 8_600;
        $quality = $base->evidenceQualityWeights;
        $quality['parse_coverage'] = 4_000;
        $quality['evidence_volume'] = 3_500;
        $competencies = $base->competencies;
        $first = $competencies[0];
        $competencies[0] = new CompetencyDefinition($first->key, $first->description, [
            new EvidenceRule('COMPLEXITY', 'mean_cyclomatic_complexity', 5_000, true, $first->evidence[0]->rationale),
            new EvidenceRule('COMPLEXITY', 'complex_function_share', 5_000, true, $first->evidence[1]->rationale),
        ], $first->partialLanguages);

        $variants = [
            new CompetencySpecification($base->version, $base->dnaScoringVersions, $base->competencies, $levels, $base->evidenceQualityWeights),
            new CompetencySpecification($base->version, $base->dnaScoringVersions, $base->competencies, $base->levels, $quality),
            new CompetencySpecification($base->version, $base->dnaScoringVersions, $competencies, $base->levels, $base->evidenceQualityWeights),
            new CompetencySpecification($base->version, ['1.0.0', '1.1.0'], $base->competencies, $base->levels, $base->evidenceQualityWeights),
            new CompetencySpecification('1.0.1', $base->dnaScoringVersions, $base->competencies, $base->levels, $base->evidenceQualityWeights),
        ];
        $fingerprints = array_map(fn (CompetencySpecification $s): string => $s->fingerprint(), $variants);

        $this->assertNotContains($base->fingerprint(), $fingerprints);
        $this->assertCount(count($variants), array_unique($fingerprints));
        $this->assertSame($base->fingerprint(), CompetencySpecification::v1_0_0()->fingerprint(), 'stable across instances');
    }

    public function test_unknown_versions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CompetencySpecification::forVersion('1.0');
    }

    public function test_invalid_specifications_cannot_be_constructed(): void
    {
        $base = CompetencySpecification::v1_0_0();
        $one = fn (string $component, CompetencyKey $key = CompetencyKey::CodeHygiene): CompetencyDefinition => new CompetencyDefinition(
            $key, 'test', [new EvidenceRule('X', $component, FixedPoint::ONE, true, 'test')], [],
        );
        $invalid = [
            'double counting' => fn () => new CompetencySpecification('9.9.9', ['1.0.0'], [$one('a'), $one('a', CompetencyKey::TypeStructure)], $base->levels, $base->evidenceQualityWeights),
            'duplicate competency' => fn () => new CompetencySpecification('9.9.9', ['1.0.0'], [$one('a'), $one('b')], $base->levels, $base->evidenceQualityWeights),
            'weights not 1' => fn () => new CompetencySpecification('9.9.9', ['1.0.0'], [new CompetencyDefinition(CompetencyKey::CodeHygiene, 't', [new EvidenceRule('X', 'a', 9_000, true, 't')], [])], $base->levels, $base->evidenceQualityWeights),
            'no required evidence' => fn () => new CompetencySpecification('9.9.9', ['1.0.0'], [new CompetencyDefinition(CompetencyKey::CodeHygiene, 't', [new EvidenceRule('X', 'a', FixedPoint::ONE, false, 't')], [])], $base->levels, $base->evidenceQualityWeights),
            'levels not increasing' => fn () => new CompetencySpecification('9.9.9', ['1.0.0'], [$one('a')], ['NOT_ESTABLISHED' => 0, 'DEVELOPING' => 5_000, 'ESTABLISHED' => 5_000, 'STRONG' => 9_000], $base->evidenceQualityWeights),
            'levels not starting at 0' => fn () => new CompetencySpecification('9.9.9', ['1.0.0'], [$one('a')], ['NOT_ESTABLISHED' => 1, 'DEVELOPING' => 5_000, 'ESTABLISHED' => 6_000, 'STRONG' => 9_000], $base->evidenceQualityWeights),
            'missing level' => fn () => new CompetencySpecification('9.9.9', ['1.0.0'], [$one('a')], ['NOT_ESTABLISHED' => 0, 'STRONG' => 9_000], $base->evidenceQualityWeights),
            'quality weights' => fn () => new CompetencySpecification('9.9.9', ['1.0.0'], [$one('a')], $base->levels, ['parse_coverage' => 5_000, 'evidence_volume' => 5_000, 'evidence_availability' => 1]),
            'no DNA version' => fn () => new CompetencySpecification('9.9.9', [], [$one('a')], $base->levels, $base->evidenceQualityWeights),
            'bad version' => fn () => new CompetencySpecification('1.0', ['1.0.0'], [$one('a')], $base->levels, $base->evidenceQualityWeights),
        ];
        foreach ($invalid as $name => $build) {
            try {
                $build();
                $this->fail("accepted: {$name}");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
