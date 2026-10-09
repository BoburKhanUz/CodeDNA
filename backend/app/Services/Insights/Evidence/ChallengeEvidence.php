<?php

declare(strict_types=1);

namespace App\Services\Insights\Evidence;

use App\Enums\Challenge\SubmissionStatus;
use App\Enums\Insights\InsightFailure;
use App\Models\ChallengeSubmission;
use App\Services\Insights\InsightException;

/**
 * Evidence for challenge feedback (Phase 29): one submission the
 * deterministic evaluator graded (PASSED or FAILED, Phase 16). Only
 * server-owned and graded facts are copied: the catalog title, competency
 * and criteria descriptions, the verdict and execution status, test
 * counts, rule limits and measured values, and each case's id, visibility
 * and status.
 *
 * Never included: the submitted source, case arguments, expected or
 * observed values, exception messages, function names from the code, or
 * anything about hidden cases beyond id and status. The model therefore
 * cannot leak a hidden test or echo the user's code.
 */
final class ChallengeEvidence
{
    public const MAX_CASES = 40;

    public const MAX_RULES = 16;

    public const MAX_CRITERIA = 16;

    private const ERROR_NAMES = ['Error', 'TypeError', 'ValueError', 'KeyError', 'IndexError', 'AttributeError', 'ZeroDivisionError', 'RecursionError', 'NameError', 'AssertionError', 'RuntimeError', 'NotImplementedError', 'StopIteration', 'OverflowError', 'ImportError', 'MemoryError'];

    private const RULE_LABELS = [
        'syntax_valid' => 'The file parses',
        'max_function_complexity' => 'Cyclomatic complexity of every function is at most the limit',
        'max_function_nesting' => 'Block nesting of every function is at most the limit',
        'max_function_lines' => 'Every function has at most the limit of lines',
        'max_function_parameters' => 'Every function has at most the limit of parameters',
        'max_class_lines' => 'Every class has at most the limit of lines',
        'max_class_methods' => 'Every class has at most the limit of methods',
        'min_classes' => 'The file defines at least the limit of classes',
    ];

    /**
     * @return array{evidence: list<array<string, mixed>>, lineage: array<string, string|null>, notes: list<string>}
     */
    public static function build(ChallengeSubmission $submission): array
    {
        if (! in_array($submission->status, [SubmissionStatus::Passed, SubmissionStatus::Failed], true) || ! is_array($submission->evaluation)) {
            throw new InsightException(InsightFailure::EvidenceInvalid, 'submission_not_graded');
        }
        $definition = $submission->definition()->firstOrFail();
        $evaluation = $submission->evaluation;
        $tests = (array) ($evaluation['tests'] ?? []);
        $verdict = Facts::token($evaluation['verdict'] ?? null);
        if (! in_array($verdict, ['PASSED', 'FAILED'], true) || $verdict !== $submission->status->value) {
            Facts::invalid('verdict');
        }

        $evidence = [
            Facts::item('challenge:definition', Facts::catalogText($definition->title, 120), 'The coding challenge (server-owned catalog). Completing it does not change any CodeDNA score or gap.', [
                'key' => Facts::token($definition->key),
                'competency' => Facts::token($definition->category),
                'difficulty' => Facts::token($definition->difficulty),
                'language' => Facts::key($definition->language),
            ]),
            Facts::item('result:verdict', 'Evaluator verdict', 'The deterministic evaluator result of this attempt. PASSED only when execution completed and every criterion passed.', [
                'verdict' => $verdict,
                'execution_status' => Facts::token($evaluation['execution']['status'] ?? null),
                'attempt_number' => Facts::count($submission->attempt_number, 100),
                'tests_total' => Facts::count($tests['total'] ?? null, 1000),
                'tests_passed' => Facts::count($tests['passed'] ?? null, 1000),
                'tests_failed' => Facts::count($tests['failed'] ?? null, 1000),
                'visible_total' => Facts::count($tests['visible']['total'] ?? null, 1000),
                'visible_passed' => Facts::count($tests['visible']['passed'] ?? null, 1000),
                'hidden_total' => Facts::count($tests['hidden']['total'] ?? null, 1000),
                'hidden_passed' => Facts::count($tests['hidden']['passed'] ?? null, 1000),
            ]),
        ];

        $criteria = array_slice((array) ($evaluation['criteria'] ?? []), 0, self::MAX_CRITERIA + 1);
        $rules = array_slice((array) ($evaluation['rules'] ?? []), 0, self::MAX_RULES + 1);
        $cases = array_slice((array) ($evaluation['cases'] ?? []), 0, self::MAX_CASES + 1);
        if (count($criteria) > self::MAX_CRITERIA || count($rules) > self::MAX_RULES || count($cases) > self::MAX_CASES) {
            throw new InsightException(InsightFailure::InputTooLarge, 'evaluation');
        }
        foreach ($criteria as $criterion) {
            $id = Facts::token($criterion['id'] ?? null);
            $evidence[] = Facts::item("criterion:{$id}", Facts::catalogText($criterion['description'] ?? null), 'An acceptance criterion of the challenge and whether this attempt met it.', [
                'status' => Facts::token($criterion['status'] ?? null),
            ]);
        }
        foreach ($rules as $rule) {
            $name = $rule['rule'] ?? null;
            if (! is_string($name) || ! isset(self::RULE_LABELS[$name])) {
                Facts::invalid('rule');
            }
            $limit = $rule['limit'] ?? null;
            $evidence[] = Facts::item("rule:{$name}", self::RULE_LABELS[$name], 'A structural rule checked on the submitted code by static inspection.', [
                'status' => Facts::token($rule['status'] ?? null),
                'limit' => is_bool($limit) ? $limit : Facts::count($limit, 10000),
                'observed' => Facts::count($rule['observed'] ?? null, 100000),
                'not_evaluated' => ($rule['not_evaluated'] ?? false) === true,
            ]);
        }
        foreach ($cases as $case) {
            $id = Facts::key($case['id'] ?? null);
            $visible = ($case['visibility'] ?? null) === 'VISIBLE';
            $error = $case['error'] ?? null;
            $evidence[] = Facts::item("case:{$id}", $visible && is_string($case['description'] ?? null) ? Facts::catalogText($case['description'], 200) : ($visible ? 'Visible test case' : 'Hidden test case'), $visible ? 'A visible test case of the challenge.' : 'A hidden test case: only its status is known.', [
                'visibility' => $visible ? 'VISIBLE' : 'HIDDEN',
                'status' => Facts::token($case['status'] ?? null),
                // The exception type only, from a fixed list; never its message.
                'error_type' => is_string($error) && in_array($error, self::ERROR_NAMES, true) ? $error : ($error === null ? null : 'Error'),
            ]);
        }

        return [
            'evidence' => $evidence,
            'lineage' => [
                'project_id' => $submission->project_id,
                'user_id' => $submission->user_id,
                'challenge_submission_id' => $submission->id,
                'challenge_instance_id' => $submission->challenge_instance_id,
                'evaluation_fingerprint' => $submission->evaluation_fingerprint,
            ],
            'notes' => [
                'The evaluator result is authoritative: tests and rules passed or failed exactly as listed.',
                'The submitted code is not included. Hidden test cases are known only by id and status.',
                'Give conceptual guidance only: no code, no solution, no hidden test details.',
                'A challenge result never changes a CodeDNA score, competency or gap.',
            ],
        ];
    }
}
