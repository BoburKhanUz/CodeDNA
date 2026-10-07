<?php

declare(strict_types=1);

namespace Tests\Unit\Roadmap;

use App\Services\Roadmap\RoadmapRules;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The versioned, fingerprinted roadmap rules.
 */
final class RoadmapRulesTest extends TestCase
{
    public function test_rules_1_0_0_are_frozen(): void
    {
        $rules = RoadmapRules::forVersion('1.0.0');

        $this->assertSame([
            'version' => '1.0.0',
            'actionable_statuses' => ['GAP'],
            'priority_rank' => ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1],
            'ordering' => ['priority desc', 'raw_gap desc', 'evidence_quality desc (missing last)', 'competency_key asc'],
            'max_tracks' => 3,
            'max_steps_per_track' => 8,
            'challenge_selection_version' => 'challenge-selection/1.0.0',
        ], $rules->toArray());
        $this->assertSame('eeac05dd35054808855ae1446bf1ef4e943a173a4b4b624b909a6cb6c6dbd60f', $rules->fingerprint());
    }

    public function test_changing_any_rule_changes_the_fingerprint(): void
    {
        $base = RoadmapRules::v1_0_0();
        $variants = [
            new RoadmapRules('1.0.1', $base->actionableStatuses, $base->priorityRank, $base->ordering, 3, 8, $base->challengeSelectionVersion),
            new RoadmapRules('1.0.0', ['GAP', 'NO_GAP'], $base->priorityRank, $base->ordering, 3, 8, $base->challengeSelectionVersion),
            new RoadmapRules('1.0.0', $base->actionableStatuses, ['HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3], $base->ordering, 3, 8, $base->challengeSelectionVersion),
            new RoadmapRules('1.0.0', $base->actionableStatuses, $base->priorityRank, array_reverse($base->ordering), 3, 8, $base->challengeSelectionVersion),
            new RoadmapRules('1.0.0', $base->actionableStatuses, $base->priorityRank, $base->ordering, 4, 8, $base->challengeSelectionVersion),
            new RoadmapRules('1.0.0', $base->actionableStatuses, $base->priorityRank, $base->ordering, 3, 9, $base->challengeSelectionVersion),
            new RoadmapRules('1.0.0', $base->actionableStatuses, $base->priorityRank, $base->ordering, 3, 8, 'challenge-selection/2.0.0'),
        ];
        foreach ($variants as $i => $variant) {
            $this->assertNotSame($base->fingerprint(), $variant->fingerprint(), "variant {$i}");
        }
    }

    public function test_an_unknown_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RoadmapRules::forVersion('0.9.0');
    }
}
