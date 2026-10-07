<?php

declare(strict_types=1);

namespace Tests\Unit\Growth;

use App\Services\Growth\GrowthRules;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The versioned, fingerprinted growth rules.
 */
final class GrowthRulesTest extends TestCase
{
    public function test_rules_1_0_0_are_frozen(): void
    {
        $rules = GrowthRules::forVersion('1.0.0');

        $this->assertSame([
            'version' => '1.0.0',
            'arithmetic' => 'integer fixed-point, 4 decimal places',
            'baseline' => 'the immediately preceding assessment of the same project, by analysis run completion',
            'compatibility' => [
                'dna_scoring_version', 'dna_specification_fingerprint', 'metrics_version',
                'competency_version', 'competency_specification_fingerprint',
                'skill_gap_version', 'skill_gap_specification_fingerprint', 'target_profile', 'target_profile_version',
            ],
            'meaningful_delta' => '0.0500',
            'meaningful_delta_inclusive' => true,
            'minimum_evidence_quality' => '0.6000',
            'measured_states' => ['DNA' => ['SCORED', 'READY'], 'COMPETENCY' => ['ASSESSED'], 'SKILL_GAP' => ['GAP', 'NO_GAP']],
            'better_direction' => ['DNA' => 'higher', 'COMPETENCY' => 'higher', 'SKILL_GAP' => 'lower'],
            'gap_transitions' => ['GAP->NO_GAP' => 'IMPROVED', 'NO_GAP->GAP' => 'REGRESSED'],
            'level_order' => ['NOT_ESTABLISHED', 'DEVELOPING', 'ESTABLISHED', 'STRONG'],
        ], $rules->toArray());
        $this->assertSame(500, $rules->meaningfulDelta);
        $this->assertSame(6000, $rules->minimumEvidenceQuality);
        $this->assertSame('b983b8b800846927fbcfed9cd806026c7994ca2a8c86ecd58d22dca021fdf321', $rules->fingerprint());
    }

    public function test_changing_any_rule_changes_the_fingerprint(): void
    {
        $base = GrowthRules::v1_0_0();
        $variants = [
            new GrowthRules('1.0.1', 500, 6000, $base->measuredStates),
            new GrowthRules('1.0.0', 400, 6000, $base->measuredStates),
            new GrowthRules('1.0.0', 500, 5000, $base->measuredStates),
            new GrowthRules('1.0.0', 500, 6000, ['SKILL_GAP' => ['GAP']] + $base->measuredStates),
        ];
        foreach ($variants as $i => $variant) {
            $this->assertNotSame($base->fingerprint(), $variant->fingerprint(), "variant {$i}");
        }
    }

    public function test_an_unknown_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GrowthRules::forVersion('2.0.0');
    }
}
