<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Challenge\SubmissionFailure;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use App\Services\Challenge\Evaluator\EvaluationRequest;
use App\Services\Challenge\Evaluator\EvaluatorException;
use RuntimeException;

/**
 * A deterministic stand-in for the isolated evaluator in application tests:
 * no code is executed. Each call plays the next mode (the last repeats) and
 * records the request it received.
 *
 * Modes: pass (every case returns the catalog's expected value, small
 * functions), wrong (every value is off), static (values right, but the
 * code breaks every metric rule), syntax, load_error, timeout, crashed,
 * unavailable, still_running, interrupted, rejected, invalid, exception.
 */
final class FakeChallengeEvaluator implements ChallengeEvaluator
{
    public int $calls = 0;

    public bool $isAvailable = true;

    /** @var list<EvaluationRequest> */
    public array $requests = [];

    /** @var list<string> */
    private array $modes;

    public function __construct(string ...$modes)
    {
        $this->modes = $modes === [] ? ['pass'] : array_values($modes);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function evaluate(EvaluationRequest $request): array
    {
        $mode = $this->modes[min($this->calls, count($this->modes) - 1)];
        $this->calls++;
        $this->requests[] = $request;

        return match ($mode) {
            'unavailable' => throw new EvaluatorException(SubmissionFailure::EvaluatorUnavailable, true, 'not_claimed'),
            'still_running' => throw new EvaluatorException(SubmissionFailure::EvaluationTimeout, true, 'still_running'),
            'interrupted' => throw new EvaluatorException(SubmissionFailure::EvaluationInterrupted, false, 'interrupted'),
            'rejected' => throw new EvaluatorException(SubmissionFailure::EvaluationRejected, false, 'rejected'),
            'invalid' => throw new EvaluatorException(SubmissionFailure::EvaluationInvalid, false, 'schema'),
            'exception' => throw new RuntimeException('evaluator exploded at /var/spool/codedna-challenges/secret'),
            default => $this->result($mode, $request),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $mode, EvaluationRequest $request): array
    {
        $expected = $this->expected($request);
        $small = ['name' => $request->entrypoint, 'line' => 1, 'lines' => 5, 'parameters' => 1, 'complexity' => 2, 'nesting' => 1];
        $huge = ['name' => 'everything', 'line' => 3, 'lines' => 400, 'parameters' => 12, 'complexity' => 40, 'nesting' => 9];
        $inspection = match ($mode) {
            'syntax' => ['syntax_valid' => false, 'syntax_error_line' => 1, 'functions' => [], 'classes' => []],
            'static' => ['syntax_valid' => true, 'syntax_error_line' => null, 'functions' => [$small, $huge], 'classes' => [['name' => 'God', 'line' => 1, 'lines' => 900, 'methods' => 40]]],
            default => ['syntax_valid' => true, 'syntax_error_line' => null, 'functions' => [$small], 'classes' => [['name' => 'Small', 'line' => 20, 'lines' => 10, 'methods' => 2]]],
        };
        $cases = array_map(fn (array $case): array => match ($mode) {
            'pass', 'static' => ['id' => $case['id'], 'status' => 'OK', 'value' => $expected[$case['id']]],
            'wrong' => ['id' => $case['id'], 'status' => 'OK', 'value' => ['not' => 'expected']],
            'load_error', 'syntax' => ['id' => $case['id'], 'status' => 'MISSING'],
            'timeout', 'crashed' => $case['id'] === $request->cases[0]['id']
                ? ['id' => $case['id'], 'status' => 'OK', 'value' => $expected[$case['id']]]
                : ['id' => $case['id'], 'status' => 'MISSING'],
            default => throw new RuntimeException("Unknown mode {$mode}"),
        }, $request->cases);

        return [
            'protocol' => 'codedna-evaluator/1',
            'id' => $request->submissionId,
            'evaluator_version' => '1.0.0',
            'runtime' => 'python3.11',
            'status' => match ($mode) {
                'syntax' => 'SYNTAX_ERROR',
                'load_error' => 'LOAD_ERROR',
                'timeout' => 'TIMEOUT',
                'crashed' => 'CRASHED',
                default => 'COMPLETED',
            },
            'load_error' => $mode === 'load_error' ? 'MissingEntrypoint' : null,
            'inspection' => $inspection,
            'cases' => $mode === 'syntax' ? [] : $cases,
            'duration_ms' => 42,
        ];
    }

    /**
     * Expected values from the catalog: the fake "runs" a perfect solution.
     *
     * @return array<string, mixed>
     */
    private function expected(EvaluationRequest $request): array
    {
        foreach (app(ChallengeCatalog::class)->definitions() as $definition) {
            if ($definition->entrypoint() === $request->entrypoint) {
                return array_column($definition->cases(), 'expected', 'id');
            }
        }
        throw new RuntimeException('Unknown entrypoint');
    }
}
