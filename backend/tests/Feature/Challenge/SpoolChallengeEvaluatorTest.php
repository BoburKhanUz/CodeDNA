<?php

declare(strict_types=1);

namespace Tests\Feature\Challenge;

use App\Enums\Challenge\SubmissionFailure;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\Evaluator\EvaluationRequest;
use App\Services\Challenge\Evaluator\EvaluatorException;
use App\Services\Challenge\Evaluator\SpoolChallengeEvaluator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Laravel side of the spool protocol (codedna-evaluator/1), against a
 * temporary spool directory: what is written, how results are read and
 * validated, and that execution is requested at most once.
 */
final class SpoolChallengeEvaluatorTest extends TestCase
{
    private const ID = '01m4abcdefghjkmnpqrstvwxyz';

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spool = sys_get_temp_dir().'/spool-'.Str::random(8);
        foreach (['', '/requests', '/work', '/results'] as $directory) {
            mkdir($this->spool.$directory);
        }
    }

    protected function tearDown(): void
    {
        foreach (['/requests', '/work', '/results', ''] as $directory) {
            foreach (array_merge(glob($this->spool.$directory.'/*') ?: [], glob($this->spool.$directory.'/.[!.]*') ?: []) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        foreach (['/requests', '/work', '/results', ''] as $directory) {
            @rmdir($this->spool.$directory);
        }
        parent::tearDown();
    }

    private function evaluator(int $wait = 1): SpoolChallengeEvaluator
    {
        return new SpoolChallengeEvaluator($this->spool, $wait, 30, 10);
    }

    private function request(string $id = self::ID): EvaluationRequest
    {
        $definition = ChallengeCatalog::forVersion('1.0.0')->find('CODE_HYGIENE_001', '1.0.0');
        $this->assertNotNull($definition);

        return EvaluationRequest::for($id, $definition, "def parse_config(text):\n    return {}\n");
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resultFile(array $overrides = []): string
    {
        return (string) json_encode($overrides + [
            'protocol' => 'codedna-evaluator/1', 'id' => self::ID, 'evaluator_version' => '1.0.0', 'runtime' => 'python3.11', 'status' => 'COMPLETED',
            'load_error' => null,
            'inspection' => ['syntax_valid' => true, 'syntax_error_line' => null, 'functions' => [], 'classes' => []],
            'cases' => [['id' => 'v1', 'status' => 'OK', 'value' => []]],
            'duration_ms' => 12,
        ]);
    }

    private function assertFailure(SubmissionFailure $failure, bool $retryable, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected an EvaluatorException');
        } catch (EvaluatorException $e) {
            $this->assertSame([$failure, $retryable], [$e->failure, $e->retryable]);
        }
    }

    /**
     * The request carries inputs only: no expected output, no visibility, no
     * rules, no command.
     */
    public function test_a_request_holds_source_entrypoint_and_inputs_only(): void
    {
        $this->assertFailure(SubmissionFailure::EvaluatorUnavailable, true, fn () => $this->evaluator()->evaluate($this->request()));

        // Withdrawn at the deadline because nothing claimed it; capture it first.
        $request = $this->request()->toArray();
        $this->assertSame(['protocol', 'id', 'language', 'entrypoint', 'source', 'cases'], array_keys($request));
        foreach ($request['cases'] as $case) {
            $this->assertSame(['id', 'args'], array_keys($case));
        }
        $encoded = (string) json_encode($request);
        foreach (['expected', 'HIDDEN', 'VISIBLE', 'rules', 'max_', 'command', 'image'] as $absent) {
            $this->assertStringNotContainsString($absent, $encoded);
        }
        $this->assertSame([], glob($this->spool.'/requests/*') ?: [], 'an unclaimed request is withdrawn at the deadline');
    }

    public function test_a_written_request_is_atomic_and_named_by_submission(): void
    {
        $evaluator = $this->evaluator();
        $answer = function (): void {
            // Simulate the evaluator: claim and answer.
            rename($this->spool.'/requests/'.self::ID.'.json', $this->spool.'/work/'.self::ID.'.json');
            file_put_contents($this->spool.'/results/'.self::ID.'.json', $this->resultFile());
        };
        $pid = pcntl_fork();
        if ($pid === 0) {
            for ($i = 0; $i < 100 && ! is_file($this->spool.'/requests/'.self::ID.'.json'); $i++) {
                usleep(10000);
            }
            $answer();
            posix_kill(getmypid(), SIGKILL);
        }

        $result = $evaluator->evaluate($this->request());
        pcntl_waitpid($pid, $status);

        $this->assertSame('COMPLETED', $result['status']);
        $this->assertSame([], glob($this->spool.'/results/*') ?: [], 'the result is consumed');
        $this->assertSame([], glob($this->spool.'/requests/.tmp-*') ?: []);
    }

    /**
     * At most once: an existing result or a claimed request is never
     * submitted again.
     */
    public function test_an_existing_result_is_collected_without_a_new_request(): void
    {
        file_put_contents($this->spool.'/results/'.self::ID.'.json', $this->resultFile());

        $this->assertSame('COMPLETED', $this->evaluator()->evaluate($this->request())['status']);
        $this->assertSame([], glob($this->spool.'/requests/*') ?: []);
    }

    public function test_a_claimed_request_is_waited_for_not_resubmitted(): void
    {
        file_put_contents($this->spool.'/work/'.self::ID.'.json', '{}');

        $this->assertFailure(SubmissionFailure::EvaluationTimeout, true, fn () => $this->evaluator()->evaluate($this->request()));
        $this->assertSame([], glob($this->spool.'/requests/*') ?: []);
        $this->assertFileExists($this->spool.'/work/'.self::ID.'.json');
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, SubmissionFailure}>
     */
    public static function badResults(): iterable
    {
        yield 'interrupted' => [['status' => 'INTERRUPTED'], SubmissionFailure::EvaluationInterrupted];
        yield 'rejected' => [['status' => 'REJECTED'], SubmissionFailure::EvaluationRejected];
        yield 'other submission' => [['id' => '01m4abcdefghjkmnpqrstvwxzz'], SubmissionFailure::EvaluationInvalid];
        yield 'unknown status' => [['status' => 'PASSED'], SubmissionFailure::EvaluationInvalid];
        yield 'extra field' => [['score' => 100], SubmissionFailure::EvaluationInvalid];
        yield 'other runtime' => [['runtime' => 'bash'], SubmissionFailure::EvaluationInvalid];
        yield 'not json' => ['nope', SubmissionFailure::EvaluationInvalid];
        yield 'oversized' => [str_repeat(' ', 262145), SubmissionFailure::EvaluationInvalid];
    }

    /**
     * @param  array<string, mixed>|string  $result
     */
    #[DataProvider('badResults')]
    public function test_unusable_results_are_permanent_failures(array|string $result, SubmissionFailure $failure): void
    {
        file_put_contents($this->spool.'/results/'.self::ID.'.json', is_string($result) ? $result : $this->resultFile($result));

        $this->assertFailure($failure, false, fn () => $this->evaluator()->evaluate($this->request()));
        $this->assertFileDoesNotExist($this->spool.'/results/'.self::ID.'.json');
    }

    public function test_an_invalid_submission_id_never_becomes_a_path(): void
    {
        $this->assertFailure(SubmissionFailure::EvaluationRejected, false, fn () => $this->evaluator()->evaluate($this->request('../../etc/passwd')));
        $this->assertSame([], glob($this->spool.'/requests/*') ?: []);
    }

    public function test_an_unwritable_spool_is_a_retryable_failure(): void
    {
        $evaluator = new SpoolChallengeEvaluator($this->spool.'/missing', 1, 30, 10);

        $this->assertFailure(SubmissionFailure::EvaluatorUnavailable, true, fn () => $evaluator->evaluate($this->request()));
    }

    public function test_availability_follows_the_heartbeat(): void
    {
        $this->assertFalse($this->evaluator()->available());
        file_put_contents($this->spool.'/heartbeat', json_encode(['at' => time(), 'version' => '1.0.0']));
        $this->assertTrue($this->evaluator()->available());
        file_put_contents($this->spool.'/heartbeat', json_encode(['at' => time() - 31]));
        $this->assertFalse($this->evaluator()->available());
        file_put_contents($this->spool.'/heartbeat', 'garbage');
        $this->assertFalse($this->evaluator()->available());
    }

    public function test_a_required_gvisor_isolation_is_only_satisfied_by_a_gvisor_heartbeat(): void
    {
        $production = new SpoolChallengeEvaluator($this->spool, 1, 30, 10, 'gvisor');
        $beat = fn (array $fields) => file_put_contents($this->spool.'/heartbeat', json_encode(['at' => time(), 'version' => '1.0.0'] + $fields));

        $beat([]);
        $this->assertFalse($production->available(), 'a heartbeat without isolation is container isolation');
        $this->assertTrue($this->evaluator()->available());
        $beat(['isolation' => 'container', 'production' => false]);
        $this->assertFalse($production->available());
        $beat(['isolation' => 'GVISOR']);
        $this->assertFalse($production->available(), 'unknown levels fail closed');
        $this->assertFalse($this->evaluator()->available(), 'unknown levels fail closed everywhere');
        $beat(['isolation' => ['gvisor']]);
        $this->assertFalse($production->available());
        $beat(['isolation' => 'gvisor', 'production' => true]);
        $this->assertTrue($production->available());
        $this->assertTrue($this->evaluator()->available(), 'stronger isolation satisfies a weaker requirement');
        file_put_contents($this->spool.'/heartbeat', json_encode(['at' => time() - 31, 'isolation' => 'gvisor']));
        $this->assertFalse($production->available(), 'a stale gVisor heartbeat is still stale');
    }

    public function test_an_unknown_required_isolation_fails_closed(): void
    {
        file_put_contents($this->spool.'/heartbeat', json_encode(['at' => time(), 'isolation' => 'gvisor']));

        $this->assertFalse((new SpoolChallengeEvaluator($this->spool, 1, 30, 10, 'none'))->available());
    }

    public function test_production_never_submits_code_to_an_evaluator_without_attested_gvisor(): void
    {
        $production = new SpoolChallengeEvaluator($this->spool, 1, 30, 10, 'gvisor');
        file_put_contents($this->spool.'/heartbeat', json_encode(['at' => time(), 'isolation' => 'container']));

        try {
            $production->evaluate($this->request());
            $this->fail('Expected an EvaluatorException');
        } catch (EvaluatorException $e) {
            // Refused before writing a request: not submitted-then-withdrawn.
            $this->assertSame([SubmissionFailure::EvaluatorUnavailable, true, 'isolation_unverified'], [$e->failure, $e->retryable, $e->detail]);
        }
        $this->assertSame([], glob($this->spool.'/requests/*') ?: [], 'nothing was submitted');
    }
}
