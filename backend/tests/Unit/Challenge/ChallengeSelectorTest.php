<?php

declare(strict_types=1);

namespace Tests\Unit\Challenge;

use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeSelectionException;
use App\Services\Challenge\ChallengeSelector;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deterministic gap -> category -> definition selection.
 */
final class ChallengeSelectorTest extends TestCase
{
    private ChallengeCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = ChallengeCatalog::forVersion('1.0.0');
    }

    /**
     * @return array<string, mixed>
     */
    private static function gap(string $key, string $status = 'GAP', ?string $priority = 'HIGH', ?string $raw = '0.3000'): array
    {
        return ['competency_key' => $key, 'status' => $status, 'priority' => $priority, 'priority_capped' => false, 'raw_gap' => $raw,
            'current_score' => '0.5000', 'target_score' => '0.8000'];
    }

    /**
     * @param  list<array<string, mixed>>  $gaps
     * @param  list<string>  $assigned
     * @param  list<string>  $passed
     */
    private function pick(array $gaps, array $assigned = [], array $passed = [], ?string $competency = null): string
    {
        return (new ChallengeSelector)->select($gaps, $this->catalog, $assigned, $passed, $competency)->definition->key();
    }

    public function test_gaps_are_ranked_by_priority_then_raw_gap_then_key(): void
    {
        $gaps = [
            self::gap('CODE_HYGIENE', priority: 'MEDIUM', raw: '0.2900'),
            self::gap('FUNCTION_DESIGN', priority: 'HIGH', raw: '0.3000'),
            self::gap('TYPE_STRUCTURE', priority: 'HIGH', raw: '0.4000'),
            self::gap('COMPLEXITY_MANAGEMENT', priority: 'HIGH', raw: '0.4000'),
        ];

        $selection = (new ChallengeSelector)->select($gaps, $this->catalog, [], []);

        $this->assertSame('COMPLEXITY_MANAGEMENT', $selection->competencyKey);
        $this->assertSame(['COMPLEXITY_MANAGEMENT', 'TYPE_STRUCTURE', 'FUNCTION_DESIGN', 'CODE_HYGIENE'], $selection->provenance['eligible_gaps']);
        $this->assertSame('TOP_PRIORITY_GAP', $selection->provenance['rule']);
        $this->assertSame(1, $selection->provenance['gap_rank']);
    }

    public function test_input_order_does_not_matter(): void
    {
        $gaps = [self::gap('CODE_HYGIENE', priority: 'LOW', raw: '0.0600'), self::gap('FUNCTION_DESIGN', priority: 'MEDIUM', raw: '0.2000'), self::gap('TYPE_STRUCTURE', 'NO_GAP', null, '0.0000')];
        $first = (new ChallengeSelector)->select($gaps, $this->catalog, [], []);
        $second = (new ChallengeSelector)->select(array_reverse($gaps), $this->catalog, [], []);

        $this->assertSame($first->provenance, $second->provenance);
        $this->assertSame('FUNCTION_DESIGN_001', $first->definition->key());
    }

    public function test_only_material_gaps_of_supported_competencies_are_eligible(): void
    {
        foreach (['NO_GAP', 'INSUFFICIENT_EVIDENCE', 'UNSUPPORTED', 'MISSING', 'NOT_TARGETED'] as $status) {
            try {
                $this->pick([self::gap('CODE_HYGIENE', $status, null, null)]);
                $this->fail("{$status} must not be eligible");
            } catch (ChallengeSelectionException $e) {
                $this->assertSame(ChallengeSelectionException::NO_ELIGIBLE_GAP, $e->reason);
            }
        }
        $this->expectExceptionObject(new ChallengeSelectionException(ChallengeSelectionException::NO_ELIGIBLE_GAP));
        $this->pick([self::gap('SECURITY')]);
    }

    public function test_difficulty_follows_the_gap_priority(): void
    {
        $this->assertSame('COMPLEXITY_MANAGEMENT_001', $this->pick([self::gap('COMPLEXITY_MANAGEMENT', priority: 'HIGH')]));
        $this->assertSame('COMPLEXITY_MANAGEMENT_001', $this->pick([self::gap('COMPLEXITY_MANAGEMENT', priority: 'MEDIUM', raw: '0.2000')]));
        $this->assertSame('COMPLEXITY_MANAGEMENT_002', $this->pick([self::gap('COMPLEXITY_MANAGEMENT', priority: 'LOW', raw: '0.0700')]));
        $this->assertSame('BEGINNER', (new ChallengeSelector)->preferredDifficulty('HIGH')->value);
        $this->assertSame('INTERMEDIATE', (new ChallengeSelector)->preferredDifficulty('LOW')->value);
    }

    public function test_the_nearest_difficulty_is_used_when_the_preferred_one_does_not_exist(): void
    {
        // TYPE_STRUCTURE has only an INTERMEDIATE challenge.
        $selection = (new ChallengeSelector)->select([self::gap('TYPE_STRUCTURE')], $this->catalog, [], []);

        $this->assertSame('TYPE_STRUCTURE_001', $selection->definition->key());
        $this->assertSame(['BEGINNER', 'INTERMEDIATE'], [$selection->provenance['preferred_difficulty'], $selection->provenance['selected_difficulty']]);
    }

    public function test_definitions_already_assigned_for_the_snapshot_are_excluded(): void
    {
        $gaps = [self::gap('COMPLEXITY_MANAGEMENT'), self::gap('CODE_HYGIENE', raw: '0.1000')];

        $this->assertSame('COMPLEXITY_MANAGEMENT_002', $this->pick($gaps, ['COMPLEXITY_MANAGEMENT_001']));
        $selection = (new ChallengeSelector)->select($gaps, $this->catalog, ['COMPLEXITY_MANAGEMENT_001', 'COMPLEXITY_MANAGEMENT_002'], []);
        $this->assertSame('CODE_HYGIENE_001', $selection->definition->key());
        $this->assertSame('NEXT_ELIGIBLE_GAP', $selection->provenance['rule']);
        $this->assertSame(2, $selection->provenance['gap_rank']);

        $this->expectExceptionObject(new ChallengeSelectionException(ChallengeSelectionException::NONE_AVAILABLE));
        $this->pick($gaps, ['COMPLEXITY_MANAGEMENT_001', 'COMPLEXITY_MANAGEMENT_002', 'CODE_HYGIENE_001']);
    }

    public function test_definitions_passed_before_in_the_project_come_last(): void
    {
        $this->assertSame('COMPLEXITY_MANAGEMENT_002', $this->pick([self::gap('COMPLEXITY_MANAGEMENT')], [], ['COMPLEXITY_MANAGEMENT_001']));
        $this->assertSame('COMPLEXITY_MANAGEMENT_001', $this->pick([self::gap('COMPLEXITY_MANAGEMENT')], [], ['COMPLEXITY_MANAGEMENT_001', 'COMPLEXITY_MANAGEMENT_002']));
    }

    public function test_a_requested_competency_is_used_only_if_it_is_an_eligible_gap(): void
    {
        $gaps = [self::gap('COMPLEXITY_MANAGEMENT'), self::gap('CODE_HYGIENE', priority: 'LOW', raw: '0.0500'), self::gap('FUNCTION_DESIGN', 'NO_GAP', null, '0.0000')];

        $selection = (new ChallengeSelector)->select($gaps, $this->catalog, [], [], 'CODE_HYGIENE');
        $this->assertSame(['CODE_HYGIENE_001', 'REQUESTED_COMPETENCY', 2], [$selection->definition->key(), $selection->provenance['rule'], $selection->provenance['gap_rank']]);

        $this->expectExceptionObject(new ChallengeSelectionException(ChallengeSelectionException::NO_ELIGIBLE_GAP));
        $this->pick($gaps, competency: 'FUNCTION_DESIGN');
    }

    public function test_a_requested_competency_never_falls_back_to_another_gap(): void
    {
        $this->expectExceptionObject(new ChallengeSelectionException(ChallengeSelectionException::NONE_AVAILABLE));
        $this->pick([self::gap('COMPLEXITY_MANAGEMENT'), self::gap('CODE_HYGIENE')], ['CODE_HYGIENE_001'], [], 'CODE_HYGIENE');
    }

    public function test_the_provenance_explains_the_selection(): void
    {
        $selection = (new ChallengeSelector)->select([self::gap('FUNCTION_DESIGN', priority: 'MEDIUM', raw: '0.1600')], $this->catalog, ['X'], ['Y']);

        $this->assertSame([
            'selection_version' => 'challenge-selection/1.0.0',
            'catalog_version' => '1.0.0',
            'catalog_fingerprint' => $this->catalog->fingerprint(),
            'rule' => 'TOP_PRIORITY_GAP',
            'requested_competency' => null,
            'eligible_gaps' => ['FUNCTION_DESIGN'],
            'gap_rank' => 1,
            'gap' => ['competency_key' => 'FUNCTION_DESIGN', 'status' => 'GAP', 'priority' => 'MEDIUM', 'priority_capped' => false,
                'raw_gap' => '0.1600', 'current_score' => '0.5000', 'target_score' => '0.8000'],
            'challenge_category' => 'FUNCTION_DESIGN',
            'preferred_difficulty' => 'BEGINNER',
            'selected_difficulty' => 'BEGINNER',
            'language' => 'python',
            'language_rule' => 'ONLY_EXECUTABLE_LANGUAGE',
            'excluded_definitions' => ['X'],
            'previously_passed' => ['Y'],
            'challenge_definition' => 'FUNCTION_DESIGN_001',
            'challenge_version' => '1.0.0',
        ], $selection->provenance);
    }

    public function test_the_same_inputs_always_give_the_same_selection(): void
    {
        $gaps = [self::gap('COMPLEXITY_MANAGEMENT', priority: 'LOW', raw: '0.0800'), self::gap('TYPE_STRUCTURE', priority: 'LOW', raw: '0.0800')];
        $first = (new ChallengeSelector)->select($gaps, $this->catalog, ['COMPLEXITY_MANAGEMENT_002'], []);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first->provenance, (new ChallengeSelector)->select($gaps, $this->catalog, ['COMPLEXITY_MANAGEMENT_002'], [])->provenance);
        }
        $this->assertSame('COMPLEXITY_MANAGEMENT_001', $first->definition->key(), 'ties on priority and raw gap are broken by key');
    }

    /**
     * Candidates of equal standing (same difficulty distance, same history)
     * are ordered by key, so the selection never depends on file order.
     */
    public function test_equally_suitable_definitions_are_ordered_by_key(): void
    {
        $directory = sys_get_temp_dir().'/catalog-'.Str::random(8);
        mkdir($directory);
        try {
            $document = json_decode((string) file_get_contents(resource_path('challenges/v1/FUNCTION_DESIGN_001.json')), true);
            foreach (['FUNCTION_DESIGN_003', 'FUNCTION_DESIGN_001'] as $key) {
                file_put_contents($directory.'/'.$key.'.json', json_encode(['key' => $key] + $document));
            }
            $catalog = new ChallengeCatalog('1.0.0', $directory);
            $gaps = [self::gap('FUNCTION_DESIGN')];

            $this->assertSame('FUNCTION_DESIGN_001', (new ChallengeSelector)->select($gaps, $catalog, [], [])->definition->key());
            $this->assertSame('FUNCTION_DESIGN_003', (new ChallengeSelector)->select($gaps, $catalog, ['FUNCTION_DESIGN_001'], [])->definition->key());
            $this->assertSame('FUNCTION_DESIGN_003', (new ChallengeSelector)->select($gaps, $catalog, [], ['FUNCTION_DESIGN_001'])->definition->key());
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }
}
