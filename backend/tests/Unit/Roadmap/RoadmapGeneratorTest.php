<?php

declare(strict_types=1);

namespace Tests\Unit\Roadmap;

use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeSelector;
use App\Services\Roadmap\DevelopmentFocusResolver;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapGenerationException;
use App\Services\Roadmap\RoadmapGenerator;
use App\Services\Roadmap\RoadmapPlan;
use App\Services\Roadmap\RoadmapRules;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gap -> roadmap: tracks, ordered steps, challenge references and the
 * deterministic fingerprint.
 */
final class RoadmapGeneratorTest extends TestCase
{
    private const SKILL_GAP = [
        'version' => '1.0.0',
        'specification_fingerprint' => '2dbc9aad5c196c26d02731c752afee32ba19efdc1ab7d54be69a190ec7331e13',
        'target_profile' => 'ENGINEERING_STANDARD',
        'target_profile_version' => '1.0.0',
    ];

    /**
     * @param  list<array<string, mixed>>  $gaps
     */
    private function generate(array $gaps, ?ChallengeCatalog $challenges = null): RoadmapPlan
    {
        $rules = RoadmapRules::v1_0_0();
        $challenges ??= ChallengeCatalog::forVersion('1.0.0');

        return (new RoadmapGenerator(new DevelopmentFocusResolver, new ChallengeSelector))
            ->generate($gaps, self::SKILL_GAP, RoadmapCatalog::forVersion('1.0.0', $challenges, $rules), $rules, $challenges);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function manyGaps(): array
    {
        return [
            DevelopmentFocusResolverTest::gap('COMPLEXITY_MANAGEMENT', raw: '0.5250'),
            DevelopmentFocusResolverTest::gap('FUNCTION_DESIGN', raw: '0.4500'),
            DevelopmentFocusResolverTest::gap('TYPE_STRUCTURE', raw: '0.7500'),
            DevelopmentFocusResolverTest::gap('CODE_HYGIENE', raw: '0.3000'),
        ];
    }

    public function test_tracks_follow_the_focus_and_steps_follow_the_tracks(): void
    {
        $plan = $this->generate(self::manyGaps());

        $this->assertSame(['TYPE_STRUCTURE', 'COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN'], array_column($plan->tracks, 'key'));
        $this->assertSame([1, 2, 3], array_column($plan->tracks, 'position'));
        $this->assertCount(6 + 7 + 8, $plan->steps);
        $this->assertSame(range(1, 21), array_column($plan->steps, 'position'));
        $this->assertSame(
            ['ts-responsibility', 'ts-find-oversized', 'ts-separate', 'ts-boundaries', 'ts-challenge', 'ts-reassess', 'cm-understand-complexity'],
            array_slice(array_column($plan->steps, 'key'), 0, 7),
        );
        $this->assertSame('fd-reassess', $plan->steps[count($plan->steps) - 1]['key']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 1], array_slice(array_column($plan->steps, 'step_position'), 0, 7));
        $this->assertSame(230 + 230 + 260, array_sum(array_column($plan->tracks, 'estimated_minutes')));
    }

    public function test_a_single_gap_gives_a_single_track(): void
    {
        $plan = $this->generate([
            DevelopmentFocusResolverTest::gap('CODE_HYGIENE'),
            DevelopmentFocusResolverTest::gap('FUNCTION_DESIGN', 'NO_GAP', raw: '0.0000'),
        ]);

        $this->assertSame(['CODE_HYGIENE'], array_column($plan->tracks, 'key'));
        $this->assertCount(6, $plan->steps);
        $this->assertSame('NO_GAP', $plan->focus->excluded[0]['reason']);
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>}>
     */
    public static function noRoadmap(): array
    {
        return [
            'no gaps' => [[DevelopmentFocusResolverTest::gap('FUNCTION_DESIGN', 'NO_GAP', raw: '0.0100')]],
            'insufficient evidence' => [[DevelopmentFocusResolverTest::gap('FUNCTION_DESIGN', 'INSUFFICIENT_EVIDENCE')]],
            'unsupported' => [[DevelopmentFocusResolverTest::gap('FUNCTION_DESIGN', 'UNSUPPORTED')]],
            'missing' => [[DevelopmentFocusResolverTest::gap('FUNCTION_DESIGN', 'MISSING')]],
            'nothing at all' => [[]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $gaps
     */
    #[DataProvider('noRoadmap')]
    public function test_no_actionable_gap_gives_no_roadmap(array $gaps): void
    {
        $this->expectException(RoadmapGenerationException::class);
        $this->generate($gaps);
    }

    /**
     * A CHALLENGE step references the Phase 16 challenge the challenge
     * selector recommends for the gap: easier for HIGH and MEDIUM gaps,
     * intermediate for LOW gaps.
     */
    public function test_challenge_steps_reference_the_recommended_challenge(): void
    {
        $challenge = fn (string $priority, string $raw): ?array => array_values(array_filter(
            $this->generate([DevelopmentFocusResolverTest::gap('FUNCTION_DESIGN', priority: $priority, raw: $raw)])->steps,
            fn (array $s): bool => $s['type'] === 'CHALLENGE',
        ))[0]['challenge'];

        $this->assertSame(['key' => 'FUNCTION_DESIGN_001', 'version' => '1.0.0', 'title' => 'Split the order summary', 'difficulty' => 'BEGINNER'], $challenge('HIGH', '0.4500'));
        $this->assertSame('FUNCTION_DESIGN_001', $challenge('MEDIUM', '0.2000')['key']);
        $this->assertSame('FUNCTION_DESIGN_002', $challenge('LOW', '0.1000')['key']);
        foreach ($this->generate(self::manyGaps())->steps as $step) {
            $this->assertSame($step['type'] === 'CHALLENGE', $step['challenge'] !== null, $step['key']);
        }
    }

    public function test_a_missing_challenge_leaves_the_step_without_a_reference(): void
    {
        $directory = sys_get_temp_dir().'/challenges-'.Str::random(8);
        mkdir($directory);
        foreach (['CODE_HYGIENE_001', 'COMPLEXITY_MANAGEMENT_001', 'FUNCTION_DESIGN_001', 'TYPE_STRUCTURE_001'] as $key) {
            copy(resource_path("challenges/v1/{$key}.json"), "{$directory}/{$key}.json");
        }
        try {
            $challenges = new ChallengeCatalog('1.0.0', $directory);
            $rules = RoadmapRules::v1_0_0();
            $catalog = RoadmapCatalog::forVersion('1.0.0', $challenges, $rules);
            // A challenge catalog without a FUNCTION_DESIGN challenge at generation time.
            $without = new ChallengeCatalog('1.0.0', $this->only($directory, 'CODE_HYGIENE_001'));
            $plan = (new RoadmapGenerator(new DevelopmentFocusResolver, new ChallengeSelector))
                ->generate([DevelopmentFocusResolverTest::gap('FUNCTION_DESIGN')], self::SKILL_GAP, $catalog, $rules, $without);
        } finally {
            foreach ([$directory, $directory.'-only'] as $dir) {
                array_map('unlink', glob($dir.'/*') ?: []);
                @rmdir($dir);
            }
        }

        $step = array_values(array_filter($plan->steps, fn (array $s): bool => $s['type'] === 'CHALLENGE'))[0];
        $this->assertNull($step['challenge']);
    }

    private function only(string $directory, string $key): string
    {
        mkdir($directory.'-only');
        copy("{$directory}/{$key}.json", "{$directory}-only/{$key}.json");

        return $directory.'-only';
    }

    /**
     * Same inputs, same roadmap; the fingerprint covers values only and
     * changes with any input.
     */
    public function test_generation_is_deterministic_and_fingerprinted(): void
    {
        $first = $this->generate(self::manyGaps());
        $second = $this->generate(array_reverse(self::manyGaps()));

        $this->assertSame($first->content, $second->content);
        $this->assertSame($first->fingerprint, $second->fingerprint);
        $this->assertSame('0b066a4d9edbd513b9a7f43a41517b14c2b6ea075b1fbe8c5f878f6701003953', $first->fingerprint);
        $this->assertSame(RoadmapGenerator::fingerprint($first->content), $first->fingerprint);
        $this->assertSame(
            ['roadmap_version', 'rules_version', 'catalog_fingerprint', 'rules_fingerprint', 'challenge_catalog', 'skill_gap', 'focus', 'tracks', 'steps'],
            array_keys($first->content),
        );
        $this->assertDoesNotMatchRegularExpression('/"[0-9a-hjkmnp-tv-z]{26}"|\d{4}-\d{2}-\d{2}T/', (string) json_encode($first->content), 'no IDs or timestamps');

        $changed = self::manyGaps();
        $changed[0]['raw_gap'] = '0.5249';
        $this->assertNotSame($first->fingerprint, $this->generate($changed)->fingerprint);
    }

    public function test_the_skill_gap_specification_is_part_of_the_fingerprint(): void
    {
        $rules = RoadmapRules::v1_0_0();
        $challenges = ChallengeCatalog::forVersion('1.0.0');
        $generator = new RoadmapGenerator(new DevelopmentFocusResolver, new ChallengeSelector);
        $catalog = RoadmapCatalog::forVersion('1.0.0', $challenges, $rules);

        $a = $generator->generate(self::manyGaps(), self::SKILL_GAP, $catalog, $rules, $challenges);
        $b = $generator->generate(self::manyGaps(), ['target_profile_version' => '1.0.1'] + self::SKILL_GAP, $catalog, $rules, $challenges);

        $this->assertNotSame($a->fingerprint, $b->fingerprint);
    }
}
