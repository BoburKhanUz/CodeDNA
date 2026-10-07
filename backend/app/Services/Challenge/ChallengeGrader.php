<?php

declare(strict_types=1);

namespace App\Services\Challenge;

use App\Services\Analyzer\CanonicalJson;

/**
 * Grades an evaluator result against a challenge definition
 * (challenge-evaluation/1.0.0, docs/architecture/coding-challenges-v1.md#evaluation).
 *
 * The evaluator only reports what it observed (returned values, exception
 * types, structural metrics); expected outputs stay here. A pure function:
 * the same definition and observations always give the same evaluation.
 *
 * Feedback is specific to the exercise: which case or rule failed and, for
 * visible cases only, expected and observed values. Hidden cases are
 * reported by id and status only. Nothing here is a CodeDNA score, and
 * nothing here changes a skill gap.
 */
final class ChallengeGrader
{
    public const VERSION = 'challenge-evaluation/1.0.0';

    public const PASSED = 'PASSED';

    public const FAILED = 'FAILED';

    /** Evaluator statuses that are graded (the code was examined). */
    /**
     * Error names that may be shown for a hidden case or as a load error
     * (Phase 21). Submitted code chooses its exception class names, so a
     * name could carry a hidden case's arguments out; only Python 3.11's
     * builtin exception names and the runner's own markers are shown, and
     * anything else becomes "Error". Visible cases keep the reported name:
     * their arguments are shown anyway.
     */
    public const ERROR_NAMES = [
        'ArithmeticError', 'AssertionError', 'AttributeError', 'BaseException', 'BaseExceptionGroup', 'BlockingIOError', 'BrokenPipeError',
        'BufferError', 'BytesWarning', 'ChildProcessError', 'ConnectionAbortedError', 'ConnectionError', 'ConnectionRefusedError',
        'ConnectionResetError', 'DeprecationWarning', 'EOFError', 'EncodingWarning', 'EnvironmentError', 'Exception', 'ExceptionGroup',
        'FileExistsError', 'FileNotFoundError', 'FloatingPointError', 'FutureWarning', 'GeneratorExit', 'IOError', 'ImportError',
        'ImportWarning', 'IndentationError', 'IndexError', 'InterruptedError', 'IsADirectoryError', 'KeyError', 'KeyboardInterrupt',
        'LookupError', 'MemoryError', 'ModuleNotFoundError', 'NameError', 'NotADirectoryError', 'NotImplementedError', 'OSError',
        'OverflowError', 'PendingDeprecationWarning', 'PermissionError', 'ProcessLookupError', 'RecursionError', 'ReferenceError',
        'ResourceWarning', 'RuntimeError', 'RuntimeWarning', 'StopAsyncIteration', 'StopIteration', 'SyntaxError', 'SyntaxWarning',
        'SystemError', 'SystemExit', 'TabError', 'TimeoutError', 'TypeError', 'UnboundLocalError', 'UnicodeDecodeError',
        'UnicodeEncodeError', 'UnicodeError', 'UnicodeTranslateError', 'UnicodeWarning', 'UserWarning', 'ValueError', 'Warning',
        'ZeroDivisionError', 'MissingEntrypoint', 'UnserializableResult', 'ValueTooLarge', 'ValueTooDeep', 'Error',
    ];

    public const GRADED_STATUSES = ['COMPLETED', 'SYNTAX_ERROR', 'LOAD_ERROR', 'TIMEOUT', 'OUTPUT_LIMIT', 'CRASHED'];

    private const MAX_SHOWN_BYTES = 1000;

    private const MAX_VIOLATIONS = 10;

    private const METRICS = [
        'max_function_complexity' => ['functions', 'complexity'],
        'max_function_nesting' => ['functions', 'nesting'],
        'max_function_lines' => ['functions', 'lines'],
        'max_function_parameters' => ['functions', 'parameters'],
        'max_class_lines' => ['classes', 'lines'],
        'max_class_methods' => ['classes', 'methods'],
    ];

    private const EXECUTION_MESSAGES = [
        'COMPLETED' => 'The code ran to completion.',
        'SYNTAX_ERROR' => 'The code does not parse, so it was not run.',
        'LOAD_ERROR' => 'The code could not be loaded, or the required function is missing.',
        'TIMEOUT' => 'The code did not finish within the time limit.',
        'OUTPUT_LIMIT' => 'The code produced more output than allowed and was stopped.',
        'CRASHED' => 'The code stopped before all cases were run.',
    ];

    /**
     * @param  array<string, mixed>  $observed  a validated evaluator result with a graded status
     * @return array<string, mixed> the evaluation, in a fixed key order
     */
    public function grade(ChallengeDefinitionData $definition, array $observed): array
    {
        $status = (string) $observed['status'];
        $inspection = is_array($observed['inspection'] ?? null) ? $observed['inspection'] : ['syntax_valid' => false, 'functions' => [], 'classes' => []];
        $observedCases = [];
        foreach (is_array($observed['cases'] ?? null) ? $observed['cases'] : [] as $case) {
            if (is_array($case) && is_string($case['id'] ?? null)) {
                $observedCases[$case['id']] ??= $case;
            }
        }

        $cases = array_map(fn (array $case): array => $this->gradeCase($case, $observedCases[$case['id']] ?? null), $definition->cases());
        $rules = $this->gradeRules($definition->rules(), $inspection);
        $ruleStatus = array_column($rules, 'status', 'rule');
        $passedBy = fn (string $visibility): bool => array_reduce(
            array_filter($cases, fn (array $c): bool => $c['visibility'] === $visibility),
            fn (bool $all, array $c): bool => $all && $c['status'] === self::PASSED,
            true,
        );
        $checks = ['tests:visible' => $passedBy(ChallengeDefinitionData::VISIBLE), 'tests:hidden' => $passedBy(ChallengeDefinitionData::HIDDEN)];
        foreach ($ruleStatus as $rule => $ruleResult) {
            $checks["rule:{$rule}"] = $ruleResult === self::PASSED;
        }
        $criteria = array_map(fn (array $criterion): array => [
            'id' => $criterion['id'],
            'description' => $criterion['description'],
            'status' => array_reduce($criterion['checks'], fn (bool $all, string $check): bool => $all && ($checks[$check] ?? false), true)
                ? self::PASSED : self::FAILED,
        ], $definition->acceptanceCriteria());

        $count = fn (string $visibility, bool $passedOnly): int => count(array_filter(
            $cases,
            fn (array $c): bool => ($visibility === '' || $c['visibility'] === $visibility) && (! $passedOnly || $c['status'] === self::PASSED),
        ));
        $passed = $status === 'COMPLETED' && ! in_array(self::FAILED, array_column($criteria, 'status'), true);

        return [
            'evaluation_version' => self::VERSION,
            'verdict' => $passed ? self::PASSED : self::FAILED,
            'execution' => [
                'status' => $status,
                'load_error' => $status === 'LOAD_ERROR' ? self::errorName($observed['load_error'] ?? null) : null,
                'message' => self::EXECUTION_MESSAGES[$status],
            ],
            'tests' => [
                'total' => $count('', false),
                'passed' => $count('', true),
                'failed' => $count('', false) - $count('', true),
                'visible' => ['total' => $count(ChallengeDefinitionData::VISIBLE, false), 'passed' => $count(ChallengeDefinitionData::VISIBLE, true)],
                'hidden' => ['total' => $count(ChallengeDefinitionData::HIDDEN, false), 'passed' => $count(ChallengeDefinitionData::HIDDEN, true)],
            ],
            'criteria' => $criteria,
            'rules' => $rules,
            'cases' => $cases,
        ];
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    public function fingerprint(array $evaluation): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode($evaluation, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>|null  $observed
     * @return array<string, mixed>
     */
    private function gradeCase(array $case, ?array $observed): array
    {
        $visible = $case['visibility'] === ChallengeDefinitionData::VISIBLE;
        $outcome = $observed['status'] ?? 'MISSING';
        $status = match (true) {
            $outcome === 'OK' && $this->same($observed['value'] ?? null, $case['expected']) => self::PASSED,
            $outcome === 'OK' => self::FAILED,
            $outcome === 'ERROR' => 'ERROR',
            default => 'NOT_RUN',
        };
        $result = ['id' => $case['id'], 'visibility' => $case['visibility'], 'status' => $status];
        if ($status === 'ERROR') {
            $result['error'] = $visible && is_string($observed['error'] ?? null)
                ? mb_substr($observed['error'], 0, 64)
                : self::errorName($observed['error'] ?? null);
        }
        if ($visible) {
            $result['description'] = $case['description'] ?? null;
            $result['args'] = $case['args'];
            $result['expected'] = $case['expected'];
            if ($status === self::FAILED) {
                $result['observed'] = $this->shown($observed['value'] ?? null);
            }
        }

        return $result;
    }

    private static function errorName(mixed $name): string
    {
        return is_string($name) && in_array($name, self::ERROR_NAMES, true) ? $name : 'Error';
    }

    /**
     * @param  array<string, int|bool>  $rules
     * @param  array<string, mixed>  $inspection
     * @return list<array<string, mixed>>
     */
    private function gradeRules(array $rules, array $inspection): array
    {
        $syntaxValid = ($inspection['syntax_valid'] ?? false) === true;
        $graded = [];
        foreach ($rules as $rule => $limit) {
            if ($rule === 'syntax_valid') {
                $graded[] = ['rule' => $rule, 'limit' => true, 'status' => $syntaxValid ? self::PASSED : self::FAILED,
                    'line' => $syntaxValid ? null : ($inspection['syntax_error_line'] ?? null)];

                continue;
            }
            if (! $syntaxValid) {
                $graded[] = ['rule' => $rule, 'limit' => $limit, 'status' => self::FAILED, 'not_evaluated' => true];

                continue;
            }
            if ($rule === 'min_classes') {
                $count = count($inspection['classes'] ?? []);
                $graded[] = ['rule' => $rule, 'limit' => $limit, 'status' => $count >= $limit ? self::PASSED : self::FAILED, 'observed' => $count];

                continue;
            }
            [$kind, $metric] = self::METRICS[$rule];
            $violations = [];
            $worst = 0;
            foreach ($inspection[$kind] ?? [] as $item) {
                $value = (int) ($item[$metric] ?? 0);
                $worst = max($worst, $value);
                if ($value > $limit) {
                    $violations[] = ['name' => mb_substr((string) ($item['name'] ?? ''), 0, 64), 'line' => (int) ($item['line'] ?? 0), 'value' => $value];
                }
            }
            $graded[] = [
                'rule' => $rule,
                'limit' => $limit,
                'status' => $violations === [] ? self::PASSED : self::FAILED,
                'observed' => $worst,
                'violations' => array_slice($violations, 0, self::MAX_VIOLATIONS),
            ];
        }

        return $graded;
    }

    /**
     * Exact JSON equality (types included: 5 and 5.0 differ). A value that
     * cannot be decoded within the depth limit never matches.
     */
    private function same(mixed $observed, mixed $expected): bool
    {
        try {
            return CanonicalJson::encode($this->decode($observed)) === CanonicalJson::encode($this->decode($expected));
        } catch (\JsonException) {
            return false;
        }
    }

    private function shown(mixed $value): mixed
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION, 32);
        if ($encoded === false) {
            return ['truncated' => '(value nested too deeply to show)'];
        }

        return strlen($encoded) <= self::MAX_SHOWN_BYTES ? $value : ['truncated' => mb_strcut($encoded, 0, self::MAX_SHOWN_BYTES)];
    }

    private function decode(mixed $value): mixed
    {
        return json_decode((string) json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 64, JSON_THROW_ON_ERROR);
    }
}
