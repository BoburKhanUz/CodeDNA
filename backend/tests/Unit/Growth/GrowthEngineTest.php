<?php

declare(strict_types=1);

namespace Tests\Unit\Growth;

use App\Services\Growth\GrowthAssessment;
use App\Services\Growth\GrowthComparison;
use App\Services\Growth\GrowthEngine;
use App\Services\Growth\GrowthRules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The deterministic growth engine: baseline, compatibility, direction,
 * threshold, evidence, transitions and levels.
 */
final class GrowthEngineTest extends TestCase
{
    private const VERSIONS = [
        'dna_scoring_version' => '1.0.0', 'dna_specification_fingerprint' => 'aaaa', 'metrics_version' => '1.1.0',
        'competency_version' => '1.0.0', 'competency_specification_fingerprint' => 'bbbb',
        'skill_gap_version' => '1.0.0', 'skill_gap_specification_fingerprint' => 'cccc',
        'target_profile' => 'ENGINEERING_STANDARD', 'target_profile_version' => '1.0.0',
    ];

    /**
     * @return array{type: string, key: string, state: string, value: string|null, evidence_quality: string|null, level: string|null}
     */
    private static function metric(string $type, string $key, string $state, ?string $value, ?string $quality = '0.9000', ?string $level = null): array
    {
        return ['type' => $type, 'key' => $key, 'state' => $state, 'value' => $value, 'evidence_quality' => $quality, 'level' => $level];
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @param  array<string, string|null>  $versions
     */
    private static function assessment(array $metrics, array $versions = []): GrowthAssessment
    {
        /** @var list<array{type: string, key: string, state: string, value: string|null, evidence_quality: string|null, level: string|null}> $metrics */
        return new GrowthAssessment($versions + self::VERSIONS, $metrics);
    }

    private function compare(?GrowthAssessment $previous, GrowthAssessment $current): GrowthComparison
    {
        return (new GrowthEngine)->compare($previous, $current, GrowthRules::v1_0_0());
    }

    /**
     * @return array<string, mixed>
     */
    private function one(array $previous, array $current): array
    {
        $comparison = $this->compare(self::assessment([$previous]), self::assessment([$current]));
        $this->assertSame('COMPARED', $comparison->status->value);
        $this->assertCount(1, $comparison->observations);

        return $comparison->observations[0];
    }

    public function test_a_first_assessment_has_no_baseline_and_no_growth(): void
    {
        $comparison = $this->compare(null, self::assessment([self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.6700')]));

        $this->assertSame('NOT_ESTABLISHED', $comparison->status->value);
        $this->assertSame([], $comparison->observations);
        $this->assertSame([], $comparison->differences);
        $this->assertSame(0, $comparison->summary['observations']);
        $this->assertSame(0, array_sum(array_map('array_sum', $comparison->summary['statuses'])));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function compatibilityFields(): array
    {
        return array_combine(GrowthRules::COMPATIBILITY, array_map(fn (string $f): array => [$f], GrowthRules::COMPATIBILITY));
    }

    /**
     * Different versions or specifications are never compared: no delta at all.
     */
    #[DataProvider('compatibilityFields')]
    public function test_any_version_or_specification_difference_is_incomparable(string $field): void
    {
        $metric = self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.4800');
        $comparison = $this->compare(self::assessment([$metric]), self::assessment([['value' => '0.9000'] + $metric], [$field => 'other']));

        $this->assertSame('INCOMPARABLE', $comparison->status->value);
        $this->assertSame([$field], $comparison->differences);
        $this->assertSame([], $comparison->observations);
    }

    public function test_scores_improve_regress_or_stay_unchanged_by_the_meaningful_delta(): void
    {
        $c = fn (string $from, string $to): array => $this->one(
            self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', $from),
            self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', $to),
        );

        $this->assertSame(['0.1900', 'IMPROVED'], [$c('0.4800', '0.6700')['delta'], $c('0.4800', '0.6700')['status']]);
        $this->assertSame(['-0.1900', 'REGRESSED'], [$c('0.6700', '0.4800')['delta'], $c('0.6700', '0.4800')['status']]);
        $this->assertSame(['0.0000', 'UNCHANGED'], [$c('0.6700', '0.6700')['delta'], $c('0.6700', '0.6700')['status']]);
        $this->assertSame('0.1300', $c('0.6100', '0.7400')['delta'], 'exact decimal arithmetic');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function boundaries(): array
    {
        return [
            'exactly +0.0500' => ['0.5000', '0.5500', 'IMPROVED'],
            'just above +0.0500' => ['0.5000', '0.5501', 'IMPROVED'],
            'just below +0.0500' => ['0.5000', '0.5499', 'UNCHANGED'],
            'exactly -0.0500' => ['0.5500', '0.5000', 'REGRESSED'],
            'just above -0.0500' => ['0.5499', '0.5000', 'UNCHANGED'],
            'just below -0.0500' => ['0.5501', '0.5000', 'REGRESSED'],
            'smallest step' => ['0.5000', '0.5001', 'UNCHANGED'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_meaningful_delta_is_inclusive_and_exact(string $from, string $to, string $status): void
    {
        $this->assertSame($status, $this->one(self::metric('DNA', 'COMPLEXITY', 'SCORED', $from), self::metric('DNA', 'COMPLEXITY', 'SCORED', $to))['status']);
    }

    /**
     * Lower gaps are better: the direction is reversed.
     */
    #[DataProvider('boundaries')]
    public function test_gap_direction_is_reversed(string $from, string $to, string $status): void
    {
        $expected = ['IMPROVED' => 'REGRESSED', 'REGRESSED' => 'IMPROVED', 'UNCHANGED' => 'UNCHANGED'][$status];
        $observation = $this->one(self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', $from), self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', $to));

        $this->assertSame($expected, $observation['status']);
        $this->assertSame('LOWER', $observation['better']);
    }

    public function test_a_gap_that_decreases_is_an_improvement(): void
    {
        $observation = $this->one(self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', '0.2800'), self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', '0.1700'));
        $this->assertSame(['0.2800', '0.1700', '-0.1100', 'IMPROVED'], [$observation['previous_value'], $observation['current_value'], $observation['delta'], $observation['status']]);

        $observation = $this->one(self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', '0.1700'), self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', '0.2400'));
        $this->assertSame(['0.0700', 'REGRESSED'], [$observation['delta'], $observation['status']]);
    }

    /**
     * A gap closing or opening is a change, even when the numbers move less
     * than the meaningful delta.
     */
    public function test_gap_transitions_are_recorded_explicitly(): void
    {
        $closed = $this->one(self::metric('SKILL_GAP', 'TYPE_STRUCTURE', 'GAP', '0.0520'), self::metric('SKILL_GAP', 'TYPE_STRUCTURE', 'NO_GAP', '0.0480'));
        $this->assertSame(['GAP', 'NO_GAP', '-0.0040', 'IMPROVED'], [$closed['previous_state'], $closed['current_state'], $closed['delta'], $closed['status']]);

        $opened = $this->one(self::metric('SKILL_GAP', 'TYPE_STRUCTURE', 'NO_GAP', '0.0480'), self::metric('SKILL_GAP', 'TYPE_STRUCTURE', 'GAP', '0.0520'));
        $this->assertSame(['NO_GAP', 'GAP', 'REGRESSED'], [$opened['previous_state'], $opened['current_state'], $opened['status']]);

        $none = $this->one(self::metric('SKILL_GAP', 'TYPE_STRUCTURE', 'NO_GAP', '0.0000'), self::metric('SKILL_GAP', 'TYPE_STRUCTURE', 'NO_GAP', '0.0300'));
        $this->assertSame('UNCHANGED', $none['status']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function unmeasured(): array
    {
        $gap = fn (string $state, ?string $value): array => self::metric('SKILL_GAP', 'FUNCTION_DESIGN', $state, $value);

        return [
            'insufficient evidence -> gap' => [$gap('INSUFFICIENT_EVIDENCE', null), $gap('GAP', '0.2000')],
            'gap -> insufficient evidence' => [$gap('GAP', '0.2000'), $gap('INSUFFICIENT_EVIDENCE', null)],
            'unsupported -> gap' => [$gap('UNSUPPORTED', null), $gap('GAP', '0.2000')],
            'missing -> no gap' => [$gap('MISSING', null), $gap('NO_GAP', '0.0000')],
            'not targeted' => [$gap('NOT_TARGETED', null), $gap('NOT_TARGETED', null)],
            'competency not assessed' => [self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'INSUFFICIENT_EVIDENCE', null), self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.9000')],
            'competency unsupported' => [self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.5000'), self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'UNSUPPORTED', null)],
            'dimension unavailable' => [self::metric('DNA', 'STRUCTURE', 'UNAVAILABLE', null), self::metric('DNA', 'STRUCTURE', 'SCORED', '0.9000')],
            'overall insufficient' => [self::metric('DNA', 'OVERALL', 'READY', '0.5000'), self::metric('DNA', 'OVERALL', 'INSUFFICIENT_DATA', null)],
            'a value with an unmeasured state' => [self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'UNSUPPORTED', '0.1000'), self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.9000')],
        ];
    }

    /**
     * Missing, unsupported or unavailable is never zero: no delta, no change.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     */
    #[DataProvider('unmeasured')]
    public function test_unmeasured_values_are_never_compared(array $previous, array $current): void
    {
        $observation = $this->one($previous, $current);

        $this->assertSame('INSUFFICIENT_EVIDENCE', $observation['status']);
        $this->assertSame([null, null, null], [$observation['previous_value'], $observation['current_value'], $observation['delta']]);
        $this->assertSame([$previous['state'], $current['state']], [$observation['previous_state'], $observation['current_state']]);
    }

    public function test_a_metric_missing_from_one_assessment_is_explicitly_missing(): void
    {
        $comparison = $this->compare(
            self::assessment([self::metric('DNA', 'OLD_DIMENSION', 'SCORED', '0.5000')]),
            self::assessment([self::metric('DNA', 'NEW_DIMENSION', 'SCORED', '0.9000')]),
        );

        $byKey = array_column($comparison->observations, null, 'metric_key');
        $this->assertSame(['SCORED', 'MISSING', 'INSUFFICIENT_EVIDENCE'], [$byKey['OLD_DIMENSION']['previous_state'], $byKey['OLD_DIMENSION']['current_state'], $byKey['OLD_DIMENSION']['status']]);
        $this->assertSame(['MISSING', 'SCORED', 'INSUFFICIENT_EVIDENCE', null], [$byKey['NEW_DIMENSION']['previous_state'], $byKey['NEW_DIMENSION']['current_state'], $byKey['NEW_DIMENSION']['status'], $byKey['NEW_DIMENSION']['delta']]);
    }

    /**
     * Low evidence on either side: the delta is kept, no change is claimed.
     */
    public function test_low_evidence_quality_claims_no_change(): void
    {
        $c = fn (?string $before, ?string $after): array => $this->one(
            self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.4000', $before),
            self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.9000', $after),
        );

        $this->assertSame(['0.5000', 'INSUFFICIENT_EVIDENCE'], [$c('0.9000', '0.5999')['delta'], $c('0.9000', '0.5999')['status']]);
        $this->assertSame('INSUFFICIENT_EVIDENCE', $c('0.5999', '0.9000')['status']);
        $this->assertSame('IMPROVED', $c('0.6000', '0.6000')['status'], 'the minimum is inclusive');
        $this->assertSame('IMPROVED', $c(null, null)['status'], 'evidence quality is only checked where it is stored');
        $gap = $this->one(self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', '0.3000', '0.5000'), self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'NO_GAP', '0.0000', '0.9000'));
        $this->assertSame('INSUFFICIENT_EVIDENCE', $gap['status'], 'thin evidence never closes a gap');
    }

    /**
     * A level change is an observation next to the score; the score decides the status.
     */
    public function test_competency_level_transitions(): void
    {
        $c = fn (string $from, string $to, string $levelFrom, string $levelTo): array => $this->one(
            self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', $from, '0.9000', $levelFrom),
            self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', $to, '0.9000', $levelTo),
        );

        $up = $c('0.4800', '0.6700', 'DEVELOPING', 'ESTABLISHED');
        $this->assertSame(['DEVELOPING', 'ESTABLISHED', 'UP', 'IMPROVED'], [$up['previous_level'], $up['current_level'], $up['level_change'], $up['status']]);
        $this->assertSame(['DOWN', 'REGRESSED'], [$c('0.9000', '0.6000', 'STRONG', 'DEVELOPING')['level_change'], $c('0.9000', '0.6000', 'STRONG', 'DEVELOPING')['status']]);
        $this->assertSame(['SAME', 'UNCHANGED'], [$c('0.5000', '0.5100', 'DEVELOPING', 'DEVELOPING')['level_change'], $c('0.5000', '0.5100', 'DEVELOPING', 'DEVELOPING')['status']]);
        $edge = $c('0.6490', '0.6510', 'DEVELOPING', 'ESTABLISHED');
        $this->assertSame(['UP', 'UNCHANGED'], [$edge['level_change'], $edge['status']], 'a level boundary crossed by a tiny change is a level event, not an improvement');
        $this->assertNull($this->one(self::metric('COMPETENCY', 'X', 'INSUFFICIENT_EVIDENCE', null), self::metric('COMPETENCY', 'X', 'ASSESSED', '0.5000', '0.9000', 'DEVELOPING'))['level_change']);
        $this->assertNull($this->one(self::metric('DNA', 'COMPLEXITY', 'SCORED', '0.5000'), self::metric('DNA', 'COMPLEXITY', 'SCORED', '0.9000'))['level_change']);
    }

    /**
     * Only a skill gap's state is a transition: a DNA or competency value is
     * classified by its delta alone, whatever its stored state label.
     */
    public function test_only_skill_gap_states_are_transitions(): void
    {
        $observation = $this->one(self::metric('DNA', 'OVERALL', 'SCORED', '0.5000'), self::metric('DNA', 'OVERALL', 'READY', '0.5100'));

        $this->assertSame('UNCHANGED', $observation['status']);
    }

    public function test_observations_are_ordered_and_summarised_categorically(): void
    {
        $previous = self::assessment([
            self::metric('DNA', 'OVERALL', 'READY', '0.5000'),
            self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.4000', '0.9000', 'DEVELOPING'),
            self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', '0.3500'),
            self::metric('SKILL_GAP', 'CODE_HYGIENE', 'NO_GAP', '0.0000'),
            self::metric('COMPETENCY', 'CODE_HYGIENE', 'ASSESSED', '0.9500', '0.9000', 'STRONG'),
        ]);
        $current = self::assessment([
            self::metric('DNA', 'OVERALL', 'READY', '0.5100'),
            self::metric('COMPETENCY', 'FUNCTION_DESIGN', 'ASSESSED', '0.7000', '0.9000', 'ESTABLISHED'),
            self::metric('SKILL_GAP', 'FUNCTION_DESIGN', 'GAP', '0.0500'),
            self::metric('SKILL_GAP', 'CODE_HYGIENE', 'GAP', '0.1000'),
            self::metric('COMPETENCY', 'CODE_HYGIENE', 'ASSESSED', '0.8000', '0.9000', 'ESTABLISHED'),
        ]);

        $comparison = $this->compare($previous, $current);

        $this->assertSame(['DNA:OVERALL', 'COMPETENCY:FUNCTION_DESIGN', 'SKILL_GAP:FUNCTION_DESIGN', 'SKILL_GAP:CODE_HYGIENE', 'COMPETENCY:CODE_HYGIENE'],
            array_map(fn (array $o): string => "{$o['metric_type']}:{$o['metric_key']}", $comparison->observations));
        $this->assertSame([
            'observations' => 5,
            'statuses' => [
                'DNA' => ['IMPROVED' => 0, 'REGRESSED' => 0, 'UNCHANGED' => 1, 'INSUFFICIENT_EVIDENCE' => 0],
                'COMPETENCY' => ['IMPROVED' => 1, 'REGRESSED' => 1, 'UNCHANGED' => 0, 'INSUFFICIENT_EVIDENCE' => 0],
                'SKILL_GAP' => ['IMPROVED' => 1, 'REGRESSED' => 1, 'UNCHANGED' => 0, 'INSUFFICIENT_EVIDENCE' => 0],
            ],
            'level_changes' => ['UP' => 1, 'DOWN' => 1],
        ], $comparison->summary);
        $this->assertEquals($comparison, $this->compare($previous, $current), 'deterministic');
    }
}
