<?php

declare(strict_types=1);

namespace Tests\Unit\Dna;

use App\Enums\Dna\DnaScoringFailure;
use App\Enums\DnaSnapshotStatus;
use App\Services\Dna\CodeDnaScoringEngine;
use App\Services\Dna\DnaScore;
use App\Services\Dna\DnaScoringException;
use App\Services\Dna\ScoringSpecification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Support\FakeAnalyzer;
use Tests\Support\StoredResults;

/**
 * CodeDnaScoringEngine with scoring version 1.0.0 on the captured analyzer
 * response (tests/Fixtures/analyzer/static-analysis-response.json), with
 * metric counts changed per case.
 */
final class CodeDnaScoringEngineTest extends TestCase
{
    /** Every component at its best: 40 functions, 10 types, 10 files, no findings. */
    private const HEALTHY = [
        'files_analyzable' => 10, 'files_parsed' => 10, 'files_parse_error' => 0,
        'functions_total' => 40, 'complexity_total' => 80, 'complexity_over_threshold' => 0, 'types' => 10,
    ];

    private const NO_FINDINGS = [
        'structure/nesting-depth' => 0, 'structure/function-length' => 0,
        'structure/parameter-count' => 0, 'structure/class-length' => 0,
    ];

    /**
     * @param  array<string, int|null>  $overall
     * @param  array<string, int>  $byRule
     */
    private function analysis(array $overall = [], array $byRule = []): stdClass
    {
        $result = FakeAnalyzer::fixture('static_analysis');
        StoredResults::set($result, $overall + self::HEALTHY, $byRule + self::NO_FINDINGS);

        return $result;
    }

    private function score(stdClass $result): DnaScore
    {
        return (new CodeDnaScoringEngine)->score($result, ScoringSpecification::v1_0_0());
    }

    /**
     * @return array<string, mixed>
     */
    private function component(DnaScore $score, string $dimension, string $key): array
    {
        return $score->dimensions[$dimension]['components'][$key];
    }

    public function test_the_same_result_always_gives_the_same_dna(): void
    {
        $first = $this->score($this->analysis(['complexity_total' => 173], ['structure/function-length' => 3]));

        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals($first, $this->score($this->analysis(['complexity_total' => 173], ['structure/function-length' => 3])));
        }

        // Key order of the input does not matter.
        $reordered = $this->reverseKeys($this->analysis(['complexity_total' => 173], ['structure/function-length' => 3]));
        $this->assertEquals($first, $this->score($reordered));
        $this->assertSame(json_encode($first), json_encode($this->score($reordered)));
    }

    public function test_only_the_specified_counts_influence_the_dna(): void
    {
        $base = $this->score($this->analysis());
        $changed = $this->analysis();
        $changed->metrics->by_language = new stdClass;
        $changed->metrics->overall->complexity_avg = 9.9;
        $changed->metrics->overall->function_lines_max = 9999;
        $changed->metrics->overall->lines_code = 1;
        $changed->findings->items = [];
        $changed->findings->truncated = true;
        $changed->findings->by_rule->{'complexity/cyclomatic'} = 999;
        $changed->versions->analyzer = '9.9.9';
        $changed->source->files_total = 123456;
        unset($changed->diagnostics, $changed->ir);

        $this->assertEquals($base, $this->score($changed));
    }

    public function test_best_possible_evidence_scores_exactly_one(): void
    {
        $score = $this->score($this->analysis());

        $this->assertSame(DnaSnapshotStatus::Ready, $score->status);
        $this->assertSame('1.0000', $score->overallScore);
        foreach ($score->dimensions as $dimension) {
            $this->assertSame('SCORED', $dimension['status']);
            $this->assertSame('1.0000', $dimension['score']);
        }
        // 0.5 × 1 (all files parsed) + 0.25 × 40/50 functions + 0.25 × 7/7 components.
        $this->assertSame('0.9500', $score->dataQuality);
    }

    public function test_worst_possible_evidence_scores_exactly_zero(): void
    {
        $score = $this->score($this->analysis(
            ['files_parsed' => 1, 'files_parse_error' => 9, 'complexity_total' => 400, 'complexity_over_threshold' => 40],
            ['structure/nesting-depth' => 40, 'structure/function-length' => 40, 'structure/parameter-count' => 40, 'structure/class-length' => 10],
        ));

        $this->assertSame(DnaSnapshotStatus::Ready, $score->status);
        $this->assertSame('0.0000', $score->overallScore);
        foreach ($score->dimensions as $dimension) {
            $this->assertSame('0.0000', $dimension['score']);
        }
    }

    /**
     * Component scores just below, exactly at and just above each threshold
     * (100 functions, 100 types, 100 parsed-or-failed files).
     *
     * @return iterable<string, array{string, string, array<string, int>, array<string, int>, string}>
     */
    public static function thresholds(): iterable
    {
        $f = ['functions_total' => 100, 'complexity_total' => 200];
        // Mean cyclomatic complexity: best 2, worst 10.
        yield 'complexity mean below best' => ['COMPLEXITY', 'mean_cyclomatic_complexity', ['complexity_total' => 150] + $f, [], '1.0000'];
        yield 'complexity mean at best' => ['COMPLEXITY', 'mean_cyclomatic_complexity', $f, [], '1.0000'];
        yield 'complexity mean just above best' => ['COMPLEXITY', 'mean_cyclomatic_complexity', ['complexity_total' => 201] + $f, [], '0.9988'];
        yield 'complexity mean midpoint' => ['COMPLEXITY', 'mean_cyclomatic_complexity', ['complexity_total' => 600] + $f, [], '0.5000'];
        yield 'complexity mean just below worst' => ['COMPLEXITY', 'mean_cyclomatic_complexity', ['complexity_total' => 999] + $f, [], '0.0013'];
        yield 'complexity mean at worst' => ['COMPLEXITY', 'mean_cyclomatic_complexity', ['complexity_total' => 1000] + $f, [], '0.0000'];
        yield 'complexity mean above worst' => ['COMPLEXITY', 'mean_cyclomatic_complexity', ['complexity_total' => 1001] + $f, [], '0.0000'];
        // Share of functions over complexity 10: worst 0.20.
        yield 'complex share zero' => ['COMPLEXITY', 'complex_function_share', $f + ['complexity_over_threshold' => 0], [], '1.0000'];
        yield 'complex share just above best' => ['COMPLEXITY', 'complex_function_share', ['complexity_over_threshold' => 1] + $f, [], '0.9500'];
        yield 'complex share just below worst' => ['COMPLEXITY', 'complex_function_share', ['complexity_over_threshold' => 19] + $f, [], '0.0500'];
        yield 'complex share at worst' => ['COMPLEXITY', 'complex_function_share', ['complexity_over_threshold' => 20] + $f, [], '0.0000'];
        yield 'complex share above worst' => ['COMPLEXITY', 'complex_function_share', ['complexity_over_threshold' => 21] + $f, [], '0.0000'];
        // Share of functions nested deeper than 4: worst 0.20.
        yield 'nesting just below worst' => ['COMPLEXITY', 'deep_nesting_share', $f, ['structure/nesting-depth' => 19], '0.0500'];
        yield 'nesting at worst' => ['COMPLEXITY', 'deep_nesting_share', $f, ['structure/nesting-depth' => 20], '0.0000'];
        yield 'nesting above worst' => ['COMPLEXITY', 'deep_nesting_share', $f, ['structure/nesting-depth' => 21], '0.0000'];
        // Share of functions over 100 lines: worst 0.10.
        yield 'function length zero' => ['STRUCTURE', 'long_function_share', $f, [], '1.0000'];
        yield 'function length just above best' => ['STRUCTURE', 'long_function_share', $f, ['structure/function-length' => 1], '0.9000'];
        yield 'function length just below worst' => ['STRUCTURE', 'long_function_share', $f, ['structure/function-length' => 9], '0.1000'];
        yield 'function length at worst' => ['STRUCTURE', 'long_function_share', $f, ['structure/function-length' => 10], '0.0000'];
        yield 'function length above worst' => ['STRUCTURE', 'long_function_share', $f, ['structure/function-length' => 11], '0.0000'];
        // Share of functions with more than 5 parameters: worst 0.20.
        yield 'parameters just below worst' => ['STRUCTURE', 'long_parameter_list_share', $f, ['structure/parameter-count' => 19], '0.0500'];
        yield 'parameters at worst' => ['STRUCTURE', 'long_parameter_list_share', $f, ['structure/parameter-count' => 20], '0.0000'];
        yield 'parameters above worst' => ['STRUCTURE', 'long_parameter_list_share', $f, ['structure/parameter-count' => 21], '0.0000'];
        // Share of types over 500 lines: worst 0.20.
        yield 'types just below worst' => ['STRUCTURE', 'large_type_share', ['types' => 100] + $f, ['structure/class-length' => 19], '0.0500'];
        yield 'types at worst' => ['STRUCTURE', 'large_type_share', ['types' => 100] + $f, ['structure/class-length' => 20], '0.0000'];
        yield 'types above worst' => ['STRUCTURE', 'large_type_share', ['types' => 100] + $f, ['structure/class-length' => 21], '0.0000'];
        // Share of files with syntax errors: worst 0.25.
        $files = ['files_analyzable' => 100];
        yield 'syntax errors zero' => ['CODE_HYGIENE', 'syntax_error_share', $files + ['files_parsed' => 100, 'files_parse_error' => 0], [], '1.0000'];
        yield 'syntax errors just above best' => ['CODE_HYGIENE', 'syntax_error_share', $files + ['files_parsed' => 99, 'files_parse_error' => 1], [], '0.9600'];
        yield 'syntax errors just below worst' => ['CODE_HYGIENE', 'syntax_error_share', $files + ['files_parsed' => 76, 'files_parse_error' => 24], [], '0.0400'];
        yield 'syntax errors at worst' => ['CODE_HYGIENE', 'syntax_error_share', $files + ['files_parsed' => 75, 'files_parse_error' => 25], [], '0.0000'];
        yield 'syntax errors above worst' => ['CODE_HYGIENE', 'syntax_error_share', $files + ['files_parsed' => 74, 'files_parse_error' => 26], [], '0.0000'];
    }

    /**
     * @param  array<string, int>  $overall
     * @param  array<string, int>  $byRule
     */
    #[DataProvider('thresholds')]
    public function test_component_thresholds(string $dimension, string $key, array $overall, array $byRule, string $expected): void
    {
        $component = $this->component($this->score($this->analysis($overall, $byRule)), $dimension, $key);

        $this->assertSame('AVAILABLE', $component['status']);
        $this->assertSame($expected, $component['score']);
    }

    public function test_components_and_dimensions_are_weighted_means(): void
    {
        // COMPLEXITY: mean 4 -> 0.75 (×0.50), 2/40 over threshold -> 0.75 (×0.25), no deep nesting -> 1 (×0.25) = 0.8125.
        // STRUCTURE: 1/40 long -> 0.75 (×0.40), parameters 1 (×0.30), types 1 (×0.30) = 0.9.
        // CODE_HYGIENE: 1 of 10 files with errors -> 0.6.
        $score = $this->score($this->analysis(
            ['complexity_total' => 160, 'complexity_over_threshold' => 2, 'files_parsed' => 9, 'files_parse_error' => 1],
            ['structure/function-length' => 1],
        ));

        $this->assertSame('0.8125', $score->dimensions['COMPLEXITY']['score']);
        $this->assertSame('0.9000', $score->dimensions['STRUCTURE']['score']);
        $this->assertSame('0.6000', $score->dimensions['CODE_HYGIENE']['score']);
        // 0.8125 × 0.4 + 0.9 × 0.4 + 0.6 × 0.2 = 0.805
        $this->assertSame('0.8050', $score->overallScore);
        $this->assertSame(['0.3250', '0.3600', '0.1200'], array_column($score->dimensions, 'contribution'));
        $this->assertSame(['0.4000', '0.4000', '0.2000'], array_column($score->dimensions, 'effective_weight'));
        $this->assertSame('4.0000', $this->component($score, 'COMPLEXITY', 'mean_cyclomatic_complexity')['value']);
        $this->assertSame(['metrics.overall.complexity_total' => 160], $this->component($score, 'COMPLEXITY', 'mean_cyclomatic_complexity')['numerator']);
        $this->assertFalse($score->calculation['aggregation']['renormalized']);
    }

    public function test_the_overall_score_is_rounded_once_from_the_exact_weighted_mean(): void
    {
        // COMPLEXITY 0.8125, STRUCTURE (4/7 long-function share... ) chosen so contributions round apart.
        $score = $this->score($this->analysis(
            ['functions_total' => 7, 'complexity_total' => 14, 'files_parsed' => 9, 'files_parse_error' => 1],
            ['structure/parameter-count' => 1],
        ));

        // STRUCTURE: parameters 1/7 -> (0.2 − 0.142857…)/0.2 = 0.2857 (×0.30); others 1 = 0.7857.
        $this->assertSame('0.2857', $this->component($score, 'STRUCTURE', 'long_parameter_list_share')['score']);
        $this->assertSame('0.7857', $score->dimensions['STRUCTURE']['score']);
        // (1 × 0.4 + 0.7857 × 0.4 + 0.6 × 0.2) = 0.83428 -> 0.8343
        $this->assertSame('0.8343', $score->overallScore);
        $this->assertSame('0.3143', $score->dimensions['STRUCTURE']['contribution']);
    }

    public function test_too_few_functions_is_insufficient_evidence_not_a_low_score(): void
    {
        $atMinimum = $this->score($this->analysis(['functions_total' => 5, 'complexity_total' => 10]));
        $this->assertSame('SCORED', $atMinimum->dimensions['COMPLEXITY']['status']);

        $below = $this->score($this->analysis(['functions_total' => 4, 'complexity_total' => 8]));
        foreach (['COMPLEXITY', 'STRUCTURE'] as $dimension) {
            $this->assertSame('UNAVAILABLE', $below->dimensions[$dimension]['status']);
            $this->assertSame('INSUFFICIENT_EVIDENCE', $below->dimensions[$dimension]['unavailable_reason']);
            $this->assertNull($below->dimensions[$dimension]['score']);
            $this->assertNull($below->dimensions[$dimension]['contribution']);
        }
        $this->assertSame('SCORED', $below->dimensions['CODE_HYGIENE']['status']);
        // One scored dimension is below the minimum of two: no overall score.
        $this->assertSame(DnaSnapshotStatus::InsufficientData, $below->status);
        $this->assertNull($below->overallScore);
        $this->assertSame(['CODE_HYGIENE'], $below->calculation['aggregation']['scored_dimensions']);
    }

    public function test_the_captured_result_is_too_small_to_rate(): void
    {
        // 3 functions, 3 files of which 1 has a syntax error.
        $score = $this->score(FakeAnalyzer::fixture('static_analysis'));

        $this->assertSame(DnaSnapshotStatus::InsufficientData, $score->status);
        $this->assertNull($score->overallScore);
        $this->assertSame('0.0000', $score->dimensions['CODE_HYGIENE']['score']);
        $this->assertSame('0.3333', $this->component($score, 'CODE_HYGIENE', 'syntax_error_share')['value']);
        // 0.5 × 2/3 + 0.25 × 3/50 + 0.25 × 1/7 = 0.33335 + 0.015 + 0.035725 = 0.3841 (each term rounded to 4 places first)
        $this->assertSame('0.3841', $score->dataQuality);
        $this->assertSame(['AVAILABLE' => 1, 'INSUFFICIENT_EVIDENCE' => 6, 'UNSUPPORTED' => 0, 'MISSING' => 0], $score->calculation['availability']);
    }

    public function test_an_unavailable_dimension_is_excluded_and_the_weights_renormalized(): void
    {
        $result = $this->analysis();
        unset($result->findings->by_rule->{'structure/nesting-depth'});
        StoredResults::set($result, ['files_parsed' => 9, 'files_parse_error' => 1]);

        $score = $this->score($result);

        $this->assertSame('UNAVAILABLE', $score->dimensions['COMPLEXITY']['status']);
        $this->assertSame('MISSING', $score->dimensions['COMPLEXITY']['unavailable_reason']);
        $this->assertSame('MISSING', $this->component($score, 'COMPLEXITY', 'deep_nesting_share')['status']);
        $this->assertSame(['findings.by_rule.structure/nesting-depth' => null], $this->component($score, 'COMPLEXITY', 'deep_nesting_share')['numerator']);
        $this->assertSame(DnaSnapshotStatus::Ready, $score->status);
        // STRUCTURE 1 × 0.4/0.6 + CODE_HYGIENE 0.6 × 0.2/0.6 = 0.6667 + 0.2 = 0.8667
        $this->assertSame('0.6667', $score->dimensions['STRUCTURE']['effective_weight']);
        $this->assertSame('0.3333', $score->dimensions['CODE_HYGIENE']['effective_weight']);
        $this->assertNull($score->dimensions['COMPLEXITY']['effective_weight']);
        $this->assertSame('0.8667', $score->overallScore);
        $this->assertTrue($score->calculation['aggregation']['renormalized']);
        $this->assertSame('0.6000', $score->calculation['aggregation']['scored_weight']);
        $this->assertSame(['COMPLEXITY'], $score->calculation['aggregation']['unavailable_dimensions']);
    }

    public function test_null_zero_missing_and_unsupported_are_distinct(): void
    {
        // Zero is a measurement: no functions over the threshold is the best score.
        $zero = $this->score($this->analysis(['complexity_over_threshold' => 0]));
        $this->assertSame('AVAILABLE', $this->component($zero, 'COMPLEXITY', 'complex_function_share')['status']);
        $this->assertSame('1.0000', $this->component($zero, 'COMPLEXITY', 'complex_function_share')['score']);

        // null declared unsupported by the analyzer.
        $unsupported = $this->analysis(['types' => null]);
        $unsupported->metrics->overall->unsupported = ['types'];
        $score = $this->score($unsupported);
        $this->assertSame('UNSUPPORTED', $this->component($score, 'STRUCTURE', 'large_type_share')['status']);
        $this->assertNull($this->component($score, 'STRUCTURE', 'large_type_share')['score']);
        // large_type_share is optional: STRUCTURE is scored from the other two, reweighted 0.4/0.7 and 0.3/0.7.
        $this->assertSame('SCORED', $score->dimensions['STRUCTURE']['status']);
        $this->assertSame('0.7000', $score->dimensions['STRUCTURE']['calculation']['available_component_weight']);
        $this->assertSame(1, $score->calculation['availability']['UNSUPPORTED']);

        // null without being declared unsupported is missing data, not unsupported.
        $missing = $this->score($this->analysis(['types' => null]));
        $this->assertSame('MISSING', $this->component($missing, 'STRUCTURE', 'large_type_share')['status']);
        $this->assertSame(1, $missing->calculation['availability']['MISSING']);

        // A required input declared unsupported makes the dimension unavailable.
        $required = $this->analysis(['functions_total' => null]);
        $required->metrics->overall->unsupported = ['functions_total'];
        $score = $this->score($required);
        $this->assertSame('UNSUPPORTED', $score->dimensions['COMPLEXITY']['unavailable_reason']);
        $this->assertSame('UNSUPPORTED', $score->dimensions['STRUCTURE']['unavailable_reason']);
        $this->assertSame(DnaSnapshotStatus::InsufficientData, $score->status);
    }

    public function test_a_result_without_files_has_no_score_and_zero_data_quality(): void
    {
        $score = $this->score($this->analysis([
            'files_analyzable' => 0, 'files_parsed' => 0, 'files_parse_error' => 0,
            'functions_total' => 0, 'complexity_total' => 0, 'types' => 0,
        ]));

        $this->assertSame(DnaSnapshotStatus::InsufficientData, $score->status);
        $this->assertNull($score->overallScore);
        $this->assertSame('0.0000', $score->dataQuality);
        foreach ($score->dimensions as $dimension) {
            $this->assertSame('UNAVAILABLE', $dimension['status']);
            $this->assertSame('INSUFFICIENT_EVIDENCE', $dimension['unavailable_reason']);
            $this->assertSame('0.0000', $dimension['data_quality']);
        }
    }

    public function test_partial_parse_failures_lower_hygiene_and_data_quality_only(): void
    {
        $clean = $this->score($this->analysis(['files_analyzable' => 20, 'files_parsed' => 20]));
        $partial = $this->score($this->analysis(['files_analyzable' => 20, 'files_parsed' => 15, 'files_parse_error' => 3]));

        // 3 errors of 18 parsed-or-failed files: (0.25 − 0.1667) / 0.25 = 0.3333.
        $this->assertSame('0.3333', $partial->dimensions['CODE_HYGIENE']['score']);
        // Function-based dimensions measure parsed files only: unchanged scores, lower data quality.
        $this->assertSame($clean->dimensions['COMPLEXITY']['components'], $partial->dimensions['COMPLEXITY']['components']);
        $this->assertSame('1.0000', $partial->dimensions['COMPLEXITY']['score']);
        $this->assertSame(['0.9500', '0.8250'], [$clean->dimensions['COMPLEXITY']['data_quality'], $partial->dimensions['COMPLEXITY']['data_quality']]);
        // Coverage 15/20 (2 files timed out or hit limits, 3 failed): 0.5 × 0.75 + 0.25 × 0.8 + 0.25 × 1 = 0.825.
        $this->assertSame('0.8250', $partial->dataQuality);
        $this->assertSame('0.7500', $partial->calculation['data_quality']['parse_coverage']['value']);
    }

    public function test_data_quality_is_bounded_and_built_from_counts_only(): void
    {
        $full = $this->score($this->analysis(['functions_total' => 500, 'complexity_total' => 1000]));
        $this->assertSame('1.0000', $full->dataQuality, 'evidence volume is capped at 50 functions');
        $this->assertSame('1.0000', $full->calculation['data_quality']['evidence_volume']['value']);

        $quality = $this->score($this->analysis(['functions_total' => 10, 'complexity_total' => 20]))->calculation['data_quality'];
        $this->assertSame(['parse_coverage', 'evidence_volume', 'metric_availability'], array_keys($quality));
        $this->assertSame('0.2000', $quality['evidence_volume']['value']);
        $this->assertSame(7, $quality['metric_availability']['components']);
    }

    public function test_dimension_entries_carry_their_evidence_and_metadata(): void
    {
        $dimension = $this->score($this->analysis())->dimensions['STRUCTURE'];

        $this->assertSame([
            'dimension', 'name', 'scoring_version', 'status', 'unavailable_reason', 'score', 'weight', 'components', 'calculation',
            'effective_weight', 'contribution', 'data_quality',
        ], array_keys($dimension));
        $this->assertSame('Structure', $dimension['name']);
        $this->assertSame('1.0.0', $dimension['scoring_version']);
        $this->assertSame(['long_function_share', 'long_parameter_list_share', 'large_type_share'], array_keys($dimension['components']));
        $this->assertSame([
            'status', 'weight', 'required', 'numerator', 'denominator', 'minimum_denominator', 'best', 'worst', 'value', 'score',
        ], array_keys($dimension['components']['large_type_share']));
        $this->assertSame(['metrics.overall.types' => 10], $dimension['components']['large_type_share']['denominator']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function invalidResults(): iterable
    {
        yield 'float count' => [['complexity_total' => 3.5], []];
        yield 'negative count' => [['functions_total' => -1], []];
        yield 'string count' => [['types' => '10'], []];
        yield 'negative finding count' => [[], ['structure/function-length' => -1]];
        yield 'more findings than functions' => [[], ['structure/function-length' => 41]];
        yield 'more complex functions than functions' => [['complexity_over_threshold' => 41], []];
        yield 'more parsed files than analyzable' => [['files_parsed' => 11], []];
    }

    /**
     * @param  array<string, mixed>  $overall
     * @param  array<string, mixed>  $byRule
     */
    #[DataProvider('invalidResults')]
    public function test_invalid_or_inconsistent_inputs_are_rejected(array $overall, array $byRule): void
    {
        $result = $this->analysis();
        foreach ($overall as $key => $value) {
            $result->metrics->overall->{$key} = $value;
        }
        foreach ($byRule as $key => $value) {
            $result->findings->by_rule->{$key} = $value;
        }

        try {
            $this->score($result);
            $this->fail('An invalid result was scored.');
        } catch (DnaScoringException $e) {
            $this->assertSame(DnaScoringFailure::ResultInvalid, $e->failure);
        }
    }

    private function reverseKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $reversed = new stdClass;
            foreach (array_reverse(get_object_vars($value), true) as $key => $item) {
                $reversed->{$key} = $this->reverseKeys($item);
            }

            return $reversed;
        }

        return is_array($value) ? array_map($this->reverseKeys(...), $value) : $value;
    }
}
