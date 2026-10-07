<?php

declare(strict_types=1);

namespace Tests\Unit\Roadmap;

use App\Services\Challenge\ChallengeCatalog;
use App\Services\Roadmap\DevelopmentFocus;
use App\Services\Roadmap\DevelopmentFocusResolver;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapRules;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deterministic development focus: which gaps are a learning need and in
 * which order.
 */
final class DevelopmentFocusResolverTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    public static function gap(string $key, string $status = 'GAP', ?string $priority = 'HIGH', ?string $raw = '0.3000', ?string $quality = '0.9000'): array
    {
        $measured = in_array($status, ['GAP', 'NO_GAP'], true);

        return [
            'competency_key' => $key,
            'status' => $status,
            'priority' => $status === 'GAP' ? $priority : null,
            'priority_capped' => $status === 'GAP' ? false : null,
            'current_score' => $measured ? '0.4000' : null,
            'target_score' => '0.7500',
            'raw_gap' => $measured ? $raw : null,
            'evidence_quality' => $quality,
            'current_level' => $measured ? 'DEVELOPING' : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $gaps
     */
    private function focus(array $gaps, ?RoadmapCatalog $catalog = null, ?RoadmapRules $rules = null): DevelopmentFocus
    {
        $rules ??= RoadmapRules::v1_0_0();

        return (new DevelopmentFocusResolver)->resolve($gaps, $catalog ?? RoadmapCatalog::forVersion('1.0.0', ChallengeCatalog::forVersion('1.0.0'), $rules), $rules);
    }

    /**
     * @return list<string>
     */
    private function order(array $gaps): array
    {
        return array_column($this->focus($gaps)->selected, 'competency_key');
    }

    public function test_priority_comes_first(): void
    {
        $this->assertSame(['CODE_HYGIENE', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE'], $this->order([
            self::gap('TYPE_STRUCTURE', priority: 'LOW', raw: '0.1400'),
            self::gap('FUNCTION_DESIGN', priority: 'MEDIUM', raw: '0.2900'),
            self::gap('CODE_HYGIENE', priority: 'HIGH', raw: '0.3000'),
        ]));
    }

    public function test_within_a_priority_the_larger_gap_comes_first(): void
    {
        $this->assertSame(['TYPE_STRUCTURE', 'CODE_HYGIENE'], $this->order([
            self::gap('CODE_HYGIENE', priority: 'MEDIUM', raw: '0.1600'),
            self::gap('TYPE_STRUCTURE', priority: 'MEDIUM', raw: '0.2500'),
        ]));
    }

    public function test_then_better_evidence_quality_and_missing_quality_last(): void
    {
        $this->assertSame(['TYPE_STRUCTURE', 'CODE_HYGIENE', 'COMPLEXITY_MANAGEMENT'], $this->order([
            self::gap('COMPLEXITY_MANAGEMENT', raw: '0.3000', quality: null),
            self::gap('CODE_HYGIENE', raw: '0.3000', quality: '0.6000'),
            self::gap('TYPE_STRUCTURE', raw: '0.3000', quality: '0.8000'),
        ]));
    }

    public function test_then_the_competency_key(): void
    {
        $this->assertSame(['CODE_HYGIENE', 'COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN'], $this->order([
            self::gap('FUNCTION_DESIGN'),
            self::gap('COMPLEXITY_MANAGEMENT'),
            self::gap('CODE_HYGIENE'),
        ]));
    }

    public function test_input_order_does_not_matter(): void
    {
        $gaps = [
            self::gap('TYPE_STRUCTURE', raw: '0.7500'), self::gap('CODE_HYGIENE', priority: 'MEDIUM', raw: '0.2000'),
            self::gap('FUNCTION_DESIGN', raw: '0.4500'), self::gap('COMPLEXITY_MANAGEMENT', priority: 'LOW', raw: '0.0500'),
        ];
        $expected = $this->focus($gaps)->toArray();
        foreach ([array_reverse($gaps), [$gaps[2], $gaps[0], $gaps[3], $gaps[1]]] as $shuffled) {
            $this->assertSame($expected, $this->focus($shuffled)->toArray());
        }
    }

    /**
     * Only a measured, material gap is a learning need. No evidence is
     * never presented as one, and no gap gives no roadmap.
     */
    public function test_only_gaps_are_actionable_and_everything_else_says_why(): void
    {
        $focus = $this->focus([
            self::gap('FUNCTION_DESIGN', 'NO_GAP', raw: '0.0200'),
            self::gap('TYPE_STRUCTURE', 'INSUFFICIENT_EVIDENCE', quality: '0.2000'),
            self::gap('COMPLEXITY_MANAGEMENT', 'UNSUPPORTED'),
            self::gap('CODE_HYGIENE', 'MISSING'),
            self::gap('NAMING', 'NOT_TARGETED'),
        ]);

        $this->assertSame([], $focus->selected);
        $this->assertSame([
            'CODE_HYGIENE' => 'MISSING', 'COMPLEXITY_MANAGEMENT' => 'UNSUPPORTED', 'FUNCTION_DESIGN' => 'NO_GAP',
            'NAMING' => 'NOT_TARGETED', 'TYPE_STRUCTURE' => 'INSUFFICIENT_EVIDENCE',
        ], array_column($focus->excluded, 'reason', 'competency_key'));
    }

    /**
     * Rules decide what is actionable: a rules version that also accepted
     * NO_GAP would select it.
     */
    public function test_the_actionable_statuses_come_from_the_rules(): void
    {
        $base = RoadmapRules::v1_0_0();
        $wider = new RoadmapRules('9.9.9', ['GAP', 'NO_GAP'], $base->priorityRank, $base->ordering, 3, 8, $base->challengeSelectionVersion);

        $this->assertSame(['FUNCTION_DESIGN'], array_column($this->focus([self::gap('FUNCTION_DESIGN', 'NO_GAP', raw: '0.0200')], null, $wider)->selected, 'competency_key'));
        $this->assertSame([], $this->focus([self::gap('FUNCTION_DESIGN', 'NO_GAP', raw: '0.0200')])->selected);
    }

    public function test_a_gap_without_a_track_is_not_a_roadmap(): void
    {
        $directory = sys_get_temp_dir().'/roadmap-'.Str::random(8);
        mkdir($directory);
        copy(resource_path('roadmaps/v1/CODE_HYGIENE.json'), "{$directory}/CODE_HYGIENE.json");
        try {
            $catalog = new RoadmapCatalog('1.0.0', ChallengeCatalog::forVersion('1.0.0'), RoadmapRules::v1_0_0(), $directory);
            $focus = $this->focus([self::gap('FUNCTION_DESIGN'), self::gap('CODE_HYGIENE', raw: '0.1000', priority: 'LOW')], $catalog);
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }

        $this->assertSame(['CODE_HYGIENE'], array_column($focus->selected, 'competency_key'));
        $this->assertSame([['FUNCTION_DESIGN', 'NO_TRACK']], array_map(fn (array $e): array => [$e['competency_key'], $e['reason']], $focus->excluded));
    }

    /**
     * At most three tracks: the fourth gap is kept, ranked, with TRACK_LIMIT.
     */
    public function test_the_focus_is_bounded_by_the_track_limit(): void
    {
        $focus = $this->focus([
            self::gap('TYPE_STRUCTURE', raw: '0.7500'), self::gap('COMPLEXITY_MANAGEMENT', raw: '0.5250'),
            self::gap('FUNCTION_DESIGN', raw: '0.4500'), self::gap('CODE_HYGIENE', raw: '0.3000'),
        ]);

        $this->assertSame(['TYPE_STRUCTURE', 'COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN'], array_column($focus->selected, 'competency_key'));
        $this->assertSame([1, 2, 3], array_column($focus->selected, 'rank'));
        $this->assertCount(1, $focus->excluded);
        $this->assertSame(['CODE_HYGIENE', 'TRACK_LIMIT', 4], [$focus->excluded[0]['competency_key'], $focus->excluded[0]['reason'], $focus->excluded[0]['rank']]);
    }

    public function test_three_gaps_all_fit(): void
    {
        $focus = $this->focus([self::gap('TYPE_STRUCTURE'), self::gap('CODE_HYGIENE'), self::gap('FUNCTION_DESIGN')]);

        $this->assertCount(3, $focus->selected);
        $this->assertSame([], $focus->excluded);
    }

    /**
     * Each focus entry says which criterion ranked it above the next one.
     */
    public function test_the_deciding_criterion_explains_the_order(): void
    {
        $focus = $this->focus([
            self::gap('TYPE_STRUCTURE', raw: '0.3000', quality: '0.9000'),
            self::gap('CODE_HYGIENE', raw: '0.4000', quality: '0.9000'),
            self::gap('COMPLEXITY_MANAGEMENT', raw: '0.3000', quality: '0.7000'),
            self::gap('FUNCTION_DESIGN', priority: 'MEDIUM', raw: '0.2000'),
        ]);
        $entries = array_merge($focus->selected, $focus->excluded);

        $this->assertSame(
            [['CODE_HYGIENE', 'RAW_GAP', 'TYPE_STRUCTURE'], ['TYPE_STRUCTURE', 'EVIDENCE_QUALITY', 'COMPLEXITY_MANAGEMENT'],
                ['COMPLEXITY_MANAGEMENT', 'PRIORITY', 'FUNCTION_DESIGN'], ['FUNCTION_DESIGN', null, null]],
            array_map(fn (array $e): array => [$e['competency_key'], $e['deciding_criterion'], $e['ranked_above']], $entries),
        );

        $tie = $this->focus([self::gap('TYPE_STRUCTURE'), self::gap('CODE_HYGIENE')]);
        $this->assertSame(['COMPETENCY_KEY', null], array_column($tie->selected, 'deciding_criterion'));
    }

    public function test_focus_entries_keep_the_gap_values_as_stored(): void
    {
        $entry = $this->focus([self::gap('FUNCTION_DESIGN', priority: 'MEDIUM', raw: '0.2500', quality: '0.5500')])->selected[0];

        $this->assertSame([
            'competency_key' => 'FUNCTION_DESIGN', 'status' => 'GAP', 'priority' => 'MEDIUM', 'priority_capped' => false,
            'current_score' => '0.4000', 'target_score' => '0.7500', 'raw_gap' => '0.2500', 'evidence_quality' => '0.5500',
            'current_level' => 'DEVELOPING', 'rank' => 1, 'ranked_above' => null, 'deciding_criterion' => null,
        ], $entry);
    }
}
