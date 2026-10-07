<?php

declare(strict_types=1);

namespace Tests\Unit\Challenge;

use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeDefinitionData;
use App\Services\Challenge\ChallengeGrader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Deterministic grading of evaluator observations against the trusted test
 * suite (challenge-evaluation/1.0.0).
 */
final class ChallengeGraderTest extends TestCase
{
    private ChallengeDefinitionData $definition;

    protected function setUp(): void
    {
        parent::setUp();
        // FUNCTION_DESIGN_001: max_function_lines 15, max_function_parameters 3; 2 visible, 4 hidden cases.
        $definition = ChallengeCatalog::forVersion('1.0.0')->find('FUNCTION_DESIGN_001', '1.0.0');
        $this->assertNotNull($definition);
        $this->definition = $definition;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $values  case id => value (default: the expected value)
     * @return array<string, mixed>
     */
    private function observed(array $overrides = [], array $values = []): array
    {
        return $overrides + [
            'status' => 'COMPLETED',
            'load_error' => null,
            'inspection' => ['syntax_valid' => true, 'syntax_error_line' => null, 'functions' => [
                ['name' => 'summarize_order', 'line' => 1, 'lines' => 12, 'parameters' => 1, 'complexity' => 2, 'nesting' => 1],
                ['name' => 'discount', 'line' => 15, 'lines' => 6, 'parameters' => 2, 'complexity' => 3, 'nesting' => 1],
            ], 'classes' => []],
            'cases' => array_map(fn (array $c): array => array_key_exists($c['id'], $values)
                ? ['id' => $c['id'], 'status' => 'OK', 'value' => $values[$c['id']]]
                : ['id' => $c['id'], 'status' => 'OK', 'value' => $c['expected']], $this->definition->cases()),
        ];
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return array<string, mixed>
     */
    private function grade(array $observed): array
    {
        return (new ChallengeGrader)->grade($this->definition, $observed);
    }

    public function test_a_correct_and_well_structured_solution_passes(): void
    {
        $evaluation = $this->grade($this->observed());

        $this->assertSame('PASSED', $evaluation['verdict']);
        $this->assertSame('challenge-evaluation/1.0.0', $evaluation['evaluation_version']);
        $this->assertSame(['total' => 6, 'passed' => 6, 'failed' => 0, 'visible' => ['total' => 2, 'passed' => 2], 'hidden' => ['total' => 4, 'passed' => 4]], $evaluation['tests']);
        $this->assertSame(['PASSED', 'PASSED', 'PASSED', 'PASSED'], array_column($evaluation['criteria'], 'status'));
        $this->assertSame(['status' => 'COMPLETED', 'load_error' => null, 'message' => 'The code ran to completion.'], $evaluation['execution']);
    }

    public function test_a_wrong_visible_case_shows_expected_and_observed(): void
    {
        $evaluation = $this->grade($this->observed(values: ['v1' => ['total_cents' => 1]]));

        $this->assertSame('FAILED', $evaluation['verdict']);
        $v1 = $evaluation['cases'][0];
        $this->assertSame(['id', 'visibility', 'status', 'description', 'args', 'expected', 'observed'], array_keys($v1));
        $this->assertSame(['FAILED', ['total_cents' => 1]], [$v1['status'], $v1['observed']]);
        $this->assertSame($this->definition->cases()[0]['expected'], $v1['expected']);
        $this->assertArrayNotHasKey('observed', $evaluation['cases'][1], 'a passed case shows no observed value');
        $this->assertSame(['FAILED', 'PASSED', 'PASSED', 'PASSED'], array_column($evaluation['criteria'], 'status'));
    }

    /**
     * Hidden cases reveal neither inputs, expected outputs nor observed values.
     */
    public function test_a_wrong_hidden_case_reveals_nothing_but_its_status(): void
    {
        $evaluation = $this->grade($this->observed(values: ['h2' => 'something else']));

        $h2 = array_values(array_filter($evaluation['cases'], fn (array $c): bool => $c['id'] === 'h2'))[0];
        $this->assertSame(['id' => 'h2', 'visibility' => 'HIDDEN', 'status' => 'FAILED'], $h2);
        $encoded = (string) json_encode($evaluation);
        foreach (array_filter($this->definition->cases(), fn (array $c): bool => $c['visibility'] === 'HIDDEN') as $case) {
            $this->assertStringNotContainsString((string) json_encode($case['args']), $encoded);
            $this->assertStringNotContainsString((string) json_encode($case['expected']), $encoded);
        }
        $this->assertStringNotContainsString('something else', $encoded);
        $this->assertSame(['PASSED', 'FAILED', 'PASSED', 'PASSED'], array_column($evaluation['criteria'], 'status'));
    }

    public function test_exceptions_and_missing_results_fail_their_cases(): void
    {
        $observed = $this->observed();
        $observed['cases'][0] = ['id' => 'v1', 'status' => 'ERROR', 'error' => 'KeyError'];
        $observed['cases'][2] = ['id' => 'h1', 'status' => 'MISSING'];
        unset($observed['cases'][3]);

        $cases = array_column($this->grade($observed)['cases'], null, 'id');

        $this->assertSame(['ERROR', 'KeyError'], [$cases['v1']['status'], $cases['v1']['error']]);
        $this->assertArrayNotHasKey('observed', $cases['v1'], 'an exception is reported by type, not as an observed value');
        $this->assertSame(['id' => 'h1', 'visibility' => 'HIDDEN', 'status' => 'NOT_RUN'], $cases['h1']);
        $this->assertSame('NOT_RUN', $cases['h2']['status']);
    }

    /**
     * Only the first observation of a case counts: a later duplicate (for
     * example one printed by the submission) cannot overwrite it.
     */
    public function test_the_first_observation_of_a_case_wins(): void
    {
        $observed = $this->observed(values: ['v1' => 'wrong']);
        $observed['cases'][] = ['id' => 'v1', 'status' => 'OK', 'value' => $this->definition->cases()[0]['expected']];

        $this->assertSame('FAILED', $this->grade($observed)['cases'][0]['status']);
    }

    public function test_a_raised_exception_never_passes_even_with_a_matching_value(): void
    {
        $observed = $this->observed();
        $observed['cases'][0] = ['id' => 'v1', 'status' => 'ERROR', 'error' => 'ValueError', 'value' => $this->definition->cases()[0]['expected']];

        $this->assertSame('ERROR', $this->grade($observed)['cases'][0]['status']);
    }

    public function test_a_load_error_name_is_reported_only_for_load_errors(): void
    {
        $this->assertSame('ImportError', $this->grade($this->observed(['status' => 'LOAD_ERROR', 'load_error' => 'ImportError']))['execution']['load_error']);
        $this->assertNull($this->grade($this->observed(['status' => 'TIMEOUT', 'load_error' => 'ImportError']))['execution']['load_error']);
    }

    public function test_at_most_ten_violations_are_listed_but_the_worst_is_reported(): void
    {
        $observed = $this->observed();
        foreach (range(1, 12) as $i) {
            $observed['inspection']['functions'][] = ['name' => "f{$i}", 'line' => 100 + $i, 'lines' => 15 + $i, 'parameters' => 1, 'complexity' => 1, 'nesting' => 0];
        }

        $rule = array_column($this->grade($observed)['rules'], null, 'rule')['max_function_lines'];

        $this->assertCount(10, $rule['violations']);
        $this->assertSame(['FAILED', 27], [$rule['status'], $rule['observed']]);
    }

    public function test_values_must_match_exactly(): void
    {
        $expected = $this->definition->cases()[0]['expected'];
        $reordered = array_reverse($expected, true);
        $floats = array_map(fn (int $v): float => (float) $v, $expected);
        $strings = array_map(fn (int $v): string => (string) $v, $expected);

        $this->assertSame('PASSED', $this->grade($this->observed(values: ['v1' => $reordered]))['cases'][0]['status'], 'key order does not matter');
        $this->assertSame('FAILED', $this->grade($this->observed(values: ['v1' => $floats]))['cases'][0]['status'], '5.0 is not 5');
        $this->assertSame('FAILED', $this->grade($this->observed(values: ['v1' => $strings]))['cases'][0]['status']);
        $this->assertSame('FAILED', $this->grade($this->observed(values: ['v1' => $expected + ['extra' => 1]]))['cases'][0]['status']);
    }

    public function test_a_deeply_nested_value_never_matches_and_is_not_shown(): void
    {
        $deep = 0;
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }
        $v1 = $this->grade($this->observed(values: ['v1' => $deep]))['cases'][0];

        $this->assertSame('FAILED', $v1['status']);
        $this->assertSame(['truncated' => '(value nested too deeply to show)'], $v1['observed']);
    }

    public function test_large_observed_values_are_truncated(): void
    {
        $v1 = $this->grade($this->observed(values: ['v1' => str_repeat('x', 5000)]))['cases'][0];

        $this->assertArrayHasKey('truncated', $v1['observed']);
        $this->assertLessThanOrEqual(1000, strlen($v1['observed']['truncated']));
    }

    public function test_rule_violations_name_the_functions_and_fail_their_criteria(): void
    {
        $observed = $this->observed();
        $observed['inspection']['functions'][] = ['name' => 'everything', 'line' => 30, 'lines' => 16, 'parameters' => 4, 'complexity' => 9, 'nesting' => 3];

        $evaluation = $this->grade($observed);
        $rules = array_column($evaluation['rules'], null, 'rule');

        $this->assertSame('FAILED', $evaluation['verdict']);
        $this->assertSame(['rule' => 'max_function_lines', 'limit' => 15, 'status' => 'FAILED', 'observed' => 16,
            'violations' => [['name' => 'everything', 'line' => 30, 'value' => 16]]], $rules['max_function_lines']);
        $this->assertSame('FAILED', $rules['max_function_parameters']['status']);
        $this->assertSame(['PASSED', 'PASSED', 'FAILED', 'FAILED'], array_column($evaluation['criteria'], 'status'));
    }

    public function test_a_limit_is_inclusive(): void
    {
        $observed = $this->observed();
        $observed['inspection']['functions'] = [['name' => 'f', 'line' => 1, 'lines' => 15, 'parameters' => 3, 'complexity' => 1, 'nesting' => 0]];

        $this->assertSame('PASSED', $this->grade($observed)['verdict']);
    }

    public function test_a_syntax_error_fails_every_rule_and_runs_nothing(): void
    {
        $evaluation = $this->grade([
            'status' => 'SYNTAX_ERROR',
            'load_error' => null,
            'inspection' => ['syntax_valid' => false, 'syntax_error_line' => 7, 'functions' => [], 'classes' => []],
            'cases' => [],
        ]);

        $this->assertSame('FAILED', $evaluation['verdict']);
        $this->assertSame(['rule' => 'syntax_valid', 'limit' => true, 'status' => 'FAILED', 'line' => 7], $evaluation['rules'][0]);
        $this->assertTrue($evaluation['rules'][1]['not_evaluated']);
        $this->assertSame(['NOT_RUN'], array_values(array_unique(array_column($evaluation['cases'], 'status'))));
        $this->assertSame('The code does not parse, so it was not run.', $evaluation['execution']['message']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unfinishedRuns(): iterable
    {
        yield 'timeout' => ['TIMEOUT', 'The code did not finish within the time limit.'];
        yield 'output limit' => ['OUTPUT_LIMIT', 'The code produced more output than allowed and was stopped.'];
        yield 'crash' => ['CRASHED', 'The code stopped before all cases were run.'];
        yield 'load error' => ['LOAD_ERROR', 'The code could not be loaded, or the required function is missing.'];
    }

    #[DataProvider('unfinishedRuns')]
    public function test_an_unfinished_run_never_passes_even_if_every_case_matched(string $status, string $message): void
    {
        $evaluation = $this->grade($this->observed(['status' => $status, 'load_error' => $status === 'LOAD_ERROR' ? 'MissingEntrypoint' : null]));

        $this->assertSame('FAILED', $evaluation['verdict']);
        $this->assertSame($message, $evaluation['execution']['message']);
        $this->assertSame($status === 'LOAD_ERROR' ? 'MissingEntrypoint' : null, $evaluation['execution']['load_error']);
    }

    public function test_class_rules(): void
    {
        $definition = ChallengeCatalog::forVersion('1.0.0')->find('TYPE_STRUCTURE_001', '1.0.0');
        $this->assertNotNull($definition);
        $observed = [
            'status' => 'COMPLETED', 'load_error' => null,
            'inspection' => ['syntax_valid' => true, 'syntax_error_line' => null, 'functions' => [], 'classes' => []],
            'cases' => array_map(fn (array $c): array => ['id' => $c['id'], 'status' => 'OK', 'value' => $c['expected']], $definition->cases()),
        ];
        $rules = array_column((new ChallengeGrader)->grade($definition, $observed)['rules'], null, 'rule');
        $this->assertSame(['FAILED', 0], [$rules['min_classes']['status'], $rules['min_classes']['observed']]);

        $observed['inspection']['classes'] = [['name' => 'Stock', 'line' => 1, 'lines' => 25, 'methods' => 5]];
        $this->assertSame('PASSED', (new ChallengeGrader)->grade($definition, $observed)['verdict']);
        $observed['inspection']['classes'][] = ['name' => 'Big', 'line' => 40, 'lines' => 26, 'methods' => 6];
        $rules = array_column((new ChallengeGrader)->grade($definition, $observed)['rules'], null, 'rule');
        $this->assertSame(['FAILED', 'FAILED'], [$rules['max_class_lines']['status'], $rules['max_class_methods']['status']]);
    }

    public function test_grading_is_deterministic(): void
    {
        $observed = $this->observed(values: ['v2' => 1, 'h3' => 2]);
        $grader = new ChallengeGrader;

        $this->assertSame($grader->grade($this->definition, $observed), $grader->grade($this->definition, $observed));
        $this->assertSame($grader->fingerprint($grader->grade($this->definition, $observed)), $grader->fingerprint($grader->grade($this->definition, $observed)));
        $this->assertNotSame($grader->fingerprint($grader->grade($this->definition, $observed)), $grader->fingerprint($grader->grade($this->definition, $this->observed())));
    }

    public function test_the_evaluation_contains_no_score(): void
    {
        $encoded = (string) json_encode($this->grade($this->observed()));

        foreach (['"score"', 'dna', 'competency_score', 'gap', 'seniority', 'level'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
    }
}
