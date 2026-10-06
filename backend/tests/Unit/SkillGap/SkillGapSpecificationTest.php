<?php

declare(strict_types=1);

namespace Tests\Unit\SkillGap;

use App\Enums\Competency\CompetencyKey;
use App\Enums\SkillGap\GapPriority;
use App\Services\SkillGap\SkillGapSpecification;
use App\Services\SkillGap\TargetProfile;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkillGapSpecificationTest extends TestCase
{
    /**
     * The definition of 1.0.0 is frozen. If this fails, the change needs a
     * new skill gap (or target profile) version, not a new fingerprint here.
     */
    public function test_version_1_0_0_is_frozen(): void
    {
        $spec = SkillGapSpecification::forVersion('1.0.0');

        $this->assertSame(['1.0.0'], SkillGapSpecification::VERSIONS);
        $this->assertSame('2dbc9aad5c196c26d02731c752afee32ba19efdc1ab7d54be69a190ec7331e13', $spec->fingerprint());
    }

    public function test_version_1_0_0_targets_thresholds_and_priorities(): void
    {
        $array = SkillGapSpecification::v1_0_0()->toArray();

        $this->assertSame(['1.0.0'], $array['competency_versions']);
        $this->assertSame(['ENGINEERING_STANDARD', '1.0.0'], [$array['target_profile']['key'], $array['target_profile']['version']]);
        $this->assertSame([
            'COMPLEXITY_MANAGEMENT' => '0.7500',
            'FUNCTION_DESIGN' => '0.7500',
            'TYPE_STRUCTURE' => '0.7500',
            'CODE_HYGIENE' => '0.9000',
        ], $array['target_profile']['targets']);
        $this->assertSame('0.0500', $array['material_gap_threshold']);
        $this->assertSame(['LOW' => '0.0500', 'MEDIUM' => '0.1500', 'HIGH' => '0.3000'], $array['priorities']);
        $this->assertSame('0.6000', $array['high_priority_minimum_evidence_quality']);
        $this->assertSame(
            array_map(fn (CompetencyKey $k): string => $k->value, CompetencyKey::cases()),
            array_keys($array['target_profile']['targets']),
            'every competency of version 1.0.0 is targeted',
        );
    }

    /**
     * @return iterable<string, array{int, int, GapPriority, bool}>
     */
    public static function priorities(): iterable
    {
        yield 'exactly material' => [500, 9_000, GapPriority::Low, false];
        yield 'just below medium' => [1_499, 9_000, GapPriority::Low, false];
        yield 'exactly medium' => [1_500, 9_000, GapPriority::Medium, false];
        yield 'just above medium' => [1_501, 9_000, GapPriority::Medium, false];
        yield 'just below high' => [2_999, 9_000, GapPriority::Medium, false];
        yield 'exactly high' => [3_000, 9_000, GapPriority::High, false];
        yield 'largest gap' => [10_000, 9_000, GapPriority::High, false];
        yield 'high at the evidence-quality bound' => [3_000, 6_000, GapPriority::High, false];
        yield 'high just below the evidence-quality bound is capped' => [3_000, 5_999, GapPriority::Medium, true];
        yield 'medium is never capped' => [2_000, 0, GapPriority::Medium, false];
    }

    #[DataProvider('priorities')]
    public function test_priority_boundaries(int $gap, int $quality, GapPriority $priority, bool $capped): void
    {
        $this->assertSame([$priority, $capped], SkillGapSpecification::v1_0_0()->priorityFor($gap, $quality));
    }

    public function test_immaterial_gaps_have_no_priority(): void
    {
        $this->expectException(LogicException::class);
        SkillGapSpecification::v1_0_0()->priorityFor(499, 10_000);
    }

    public function test_any_change_to_the_definition_changes_the_fingerprint(): void
    {
        $base = SkillGapSpecification::v1_0_0();
        $profile = $base->targetProfile;
        $targets = $profile->targets;
        $targets['CODE_HYGIENE'] = 8_500;
        $rationales = $profile->rationales;
        $rationales['CODE_HYGIENE'] = 'changed';
        $priorities = $base->priorities;
        $priorities['HIGH'] = 3_500;
        $variants = [
            new SkillGapSpecification('1.0.0', $base->competencyVersions, new TargetProfile($profile->key, $profile->version, $profile->description, $targets, $profile->rationales), $base->materialGapThreshold, $base->priorities, $base->highPriorityMinimumEvidenceQuality),
            new SkillGapSpecification('1.0.0', $base->competencyVersions, new TargetProfile($profile->key, $profile->version, $profile->description, $profile->targets, $rationales), $base->materialGapThreshold, $base->priorities, $base->highPriorityMinimumEvidenceQuality),
            new SkillGapSpecification('1.0.0', $base->competencyVersions, new TargetProfile('OTHER_STANDARD', $profile->version, $profile->description, $profile->targets, $profile->rationales), $base->materialGapThreshold, $base->priorities, $base->highPriorityMinimumEvidenceQuality),
            new SkillGapSpecification('1.0.0', $base->competencyVersions, new TargetProfile($profile->key, '1.0.1', $profile->description, $profile->targets, $profile->rationales), $base->materialGapThreshold, $base->priorities, $base->highPriorityMinimumEvidenceQuality),
            new SkillGapSpecification('1.0.0', $base->competencyVersions, $profile, $base->materialGapThreshold, $priorities, $base->highPriorityMinimumEvidenceQuality),
            new SkillGapSpecification('1.0.0', $base->competencyVersions, $profile, $base->materialGapThreshold, $base->priorities, 5_000),
            new SkillGapSpecification('1.0.0', ['1.0.0', '1.1.0'], $profile, $base->materialGapThreshold, $base->priorities, $base->highPriorityMinimumEvidenceQuality),
            new SkillGapSpecification('1.0.1', $base->competencyVersions, $profile, $base->materialGapThreshold, $base->priorities, $base->highPriorityMinimumEvidenceQuality),
        ];
        $fingerprints = array_map(fn (SkillGapSpecification $s): string => $s->fingerprint(), $variants);

        $this->assertNotContains($base->fingerprint(), $fingerprints);
        $this->assertCount(count($variants), array_unique($fingerprints));
        $this->assertSame($base->fingerprint(), SkillGapSpecification::v1_0_0()->fingerprint());
    }

    public function test_unknown_versions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SkillGapSpecification::forVersion('1.0');
    }

    public function test_invalid_specifications_cannot_be_constructed(): void
    {
        $base = SkillGapSpecification::v1_0_0();
        $profile = fn (array $targets, ?array $rationales = null): TargetProfile => new TargetProfile(
            'ENGINEERING_STANDARD', '1.0.0', 'd', $targets, $rationales ?? array_map(fn (): string => 'r', $targets),
        );
        $build = fn (TargetProfile $p, int $threshold = 500, ?array $priorities = null, int $quality = 6_000, string $version = '9.9.9', array $competencyVersions = ['1.0.0']): SkillGapSpecification => new SkillGapSpecification(
            $version, $competencyVersions, $p, $threshold, $priorities ?? $base->priorities, $quality,
        );
        $ok = $profile(['CODE_HYGIENE' => 9_000]);

        $invalid = [
            'unknown competency' => fn () => $build($profile(['DATABASE_ENGINEERING' => 8_000])),
            'zero target' => fn () => $build($profile(['CODE_HYGIENE' => 0])),
            'target above 1' => fn () => $build($profile(['CODE_HYGIENE' => 10_001])),
            'no targets' => fn () => $build($profile([])),
            'missing rationale' => fn () => $build($profile(['CODE_HYGIENE' => 9_000], [])),
            'zero threshold' => fn () => $build($ok, 0, ['LOW' => 0, 'MEDIUM' => 1_500, 'HIGH' => 3_000]),
            'LOW not at the threshold' => fn () => $build($ok, 500, ['LOW' => 400, 'MEDIUM' => 1_500, 'HIGH' => 3_000]),
            'priorities not increasing' => fn () => $build($ok, 500, ['LOW' => 500, 'MEDIUM' => 3_000, 'HIGH' => 3_000]),
            'missing priority' => fn () => $build($ok, 500, ['LOW' => 500, 'HIGH' => 3_000]),
            'evidence-quality bound' => fn () => $build($ok, 500, null, 10_001),
            'bad version' => fn () => $build($ok, 500, null, 6_000, '1.0'),
            'no competency version' => fn () => $build($ok, 500, null, 6_000, '9.9.9', []),
            'seniority-like profile key' => fn () => $build(new TargetProfile('senior engineer', '1.0.0', 'd', ['CODE_HYGIENE' => 9_000], ['CODE_HYGIENE' => 'r'])),
        ];
        foreach ($invalid as $name => $construct) {
            try {
                $construct();
                $this->fail("accepted: {$name}");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
