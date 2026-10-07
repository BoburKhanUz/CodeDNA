<?php

declare(strict_types=1);

namespace Tests\Feature\Challenge;

use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeDefinitionData;
use App\Services\Challenge\ChallengeGrader;
use App\Services\Challenge\Evaluator\EvaluationRequest;
use App\Services\Challenge\Evaluator\EvaluatorException;
use App\Services\Challenge\Evaluator\SpoolChallengeEvaluator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cross-service contract (Phase 22): Laravel's spool client against the
 * real, running evaluator. Every result passes through the client's own
 * schema check (evaluator-result-1.schema.json) and the real grader.
 *
 * Runs where the spool volume is mounted and the evaluator's heartbeat is
 * fresh (`make test` runs PHPUnit in the queue container). It is skipped
 * elsewhere, unless CODEDNA_REQUIRE_EVALUATOR=1, which `make test` and CI
 * set, so a missing evaluator fails the suite instead of passing silently.
 */
final class RealEvaluatorIntegrationTest extends TestCase
{
    private SpoolChallengeEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SpoolChallengeEvaluator((string) config('codedna.challenges.spool_path'), 30, 30, 50);
        if (! $this->evaluator->available()) {
            if (getenv('CODEDNA_REQUIRE_EVALUATOR') === '1') {
                $this->fail('The evaluator is required (CODEDNA_REQUIRE_EVALUATOR=1) but its spool heartbeat is missing or stale.');
            }
            $this->markTestSkipped('The evaluator spool is not available here.');
        }
    }

    private function definition(string $key): ChallengeDefinitionData
    {
        $definition = ChallengeCatalog::forVersion('1.0.0')->find($key, '1.0.0');
        $this->assertNotNull($definition);

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(ChallengeDefinitionData $definition, string $source): array
    {
        return $this->evaluator->evaluate(EvaluationRequest::for(strtolower((string) Str::ulid()), $definition, $source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keys(): array
    {
        $keys = array_map(fn (string $path): string => basename($path, '.py'), glob(__DIR__.'/../../Fixtures/challenges/*.py') ?: []);

        return array_combine($keys, array_map(fn (string $key): array => [$key], $keys));
    }

    public function test_every_catalog_challenge_has_a_reference_solution(): void
    {
        $catalog = array_map(fn (ChallengeDefinitionData $d): string => $d->key(), ChallengeCatalog::forVersion('1.0.0')->definitions());

        $this->assertEqualsCanonicalizing($catalog, array_keys(self::keys()));
    }

    #[DataProvider('keys')]
    public function test_the_reference_solution_passes_and_the_starter_code_does_not(string $key): void
    {
        $definition = $this->definition($key);
        $grader = app(ChallengeGrader::class);

        $solution = $this->evaluate($definition, (string) file_get_contents(__DIR__."/../../Fixtures/challenges/{$key}.py"));
        $this->assertSame('COMPLETED', $solution['status']);
        $this->assertSame('PASSED', $grader->grade($definition, $solution)['verdict'], "{$key}: the reference solution must pass");

        $starter = $this->evaluate($definition, (string) $definition->document['starter_code']);
        $this->assertSame('FAILED', $grader->grade($definition, $starter)['verdict'], "{$key}: the starter code must not pass");
    }

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function hostile(): array
    {
        return [
            'syntax error' => ["def parse_config(text)\n    return {}\n", 'SYNTAX_ERROR', null],
            'exception while loading' => ["raise KeyError('x')\n", 'LOAD_ERROR', 'KeyError'],
            'endless loop' => ["while True:\n    pass\n", 'TIMEOUT', null],
            'endless loop in the entrypoint' => ["def parse_config(text):\n    while True:\n        pass\n", 'TIMEOUT', null],
            'kills itself' => ["import os, signal\nos.kill(os.getpid(), signal.SIGKILL)\n", 'CRASHED', null],
            'exits while loading' => ["import os\nos._exit(0)\n", 'CRASHED', null],
            'floods the output' => ["import sys\nsys.stdout.write('x' * 50_000_000)\ndef parse_config(text):\n    return {}\n", 'COMPLETED', null],
            // The supervisor runs as another user: the kill is refused.
            'signals its parent' => ["import os, signal\nos.kill(os.getppid(), signal.SIGTERM)\ndef parse_config(text):\n    return {}\n", 'LOAD_ERROR', 'PermissionError'],
            // Linux reports success for kill(-1) when every target refuses; nothing is signalled,
            // so loading simply goes on and finds no entrypoint.
            'signals every process' => ["import os, signal\nos.kill(-1, signal.SIGKILL)\n", 'LOAD_ERROR', 'MissingEntrypoint'],
        ];
    }

    #[DataProvider('hostile')]
    public function test_hostile_code_gets_exactly_one_valid_result(string $source, string $status, ?string $loadError): void
    {
        $definition = $this->definition('CODE_HYGIENE_001');

        $result = $this->evaluate($definition, $source);

        $this->assertSame([$status, $loadError], [$result['status'], $result['load_error']]);
        $this->assertSame('FAILED', app(ChallengeGrader::class)->grade($definition, $result)['verdict']);
        // The evaluator is still serving: the next submission is evaluated normally.
        $this->assertSame('COMPLETED', $this->evaluate($definition, (string) file_get_contents(__DIR__.'/../../Fixtures/challenges/CODE_HYGIENE_001.py'))['status']);
    }

    public function test_both_slots_serve_concurrent_submissions_independently(): void
    {
        $definition = $this->definition('CODE_HYGIENE_001');
        $solution = (string) file_get_contents(__DIR__.'/../../Fixtures/challenges/CODE_HYGIENE_001.py');
        $pipes = [];
        $sources = [$solution, "while True:\n    pass\n", $solution, "def parse_config(text)\n"];
        foreach ($sources as $index => $source) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($pair);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($pair[0]);
                try {
                    $status = $this->evaluate($definition, $source)['status'];
                } catch (EvaluatorException $e) {
                    $status = 'EXCEPTION:'.$e->failure->value;
                }
                fwrite($pair[1], (string) $status);
                fclose($pair[1]);
                exit(0);
            }
            fclose($pair[1]);
            $pipes[$index] = [$pid, $pair[0]];
        }

        $statuses = [];
        foreach ($pipes as $index => [$pid, $pipe]) {
            $statuses[$index] = stream_get_contents($pipe);
            fclose($pipe);
            pcntl_waitpid($pid, $exit);
        }

        $this->assertSame(['COMPLETED', 'TIMEOUT', 'COMPLETED', 'SYNTAX_ERROR'], $statuses);
    }
}
