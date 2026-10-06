<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Services\Analyzer\CanonicalJson;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\FakeAnalyzer;
use Tests\TestCase;

/**
 * The analysis job: claim, call, verify, persist, retry and fail
 * (docs/architecture/data-flow.md#queue-job).
 */
final class AnalyzeSourceSnapshotJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logs = [];

    /** @var (Closure(Request): mixed)|null */
    private ?Closure $handler = null;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAnalyzer::configure();
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
        // One stub that delegates: a second Http::fake() would be appended, not replace the first.
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => ($this->handler ?? throw new RuntimeException('no handler'))($r)]);
    }

    private function queuedRun(AnalysisResultType $type = AnalysisResultType::StaticAnalysis): AnalysisRun
    {
        return AnalysisRun::factory()->create(['result_type' => $type]);
    }

    /**
     * @param  Closure(Request): mixed  $handler
     */
    private function fakeAnalyzer(Closure $handler): void
    {
        $this->handler = $handler;
    }

    private function runJob(AnalyzeSourceSnapshot $job): AnalyzeSourceSnapshot
    {
        app()->call([$job, 'handle']);

        return $job;
    }

    private function newJob(AnalysisRun $run): AnalyzeSourceSnapshot
    {
        return (new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions();
    }

    public function test_the_job_carries_only_the_run_id_and_targets_the_analysis_queue(): void
    {
        $job = new AnalyzeSourceSnapshot($this->queuedRun()->id);

        $this->assertSame('analysis', $job->connection);
        $this->assertSame('analysis', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame(330, $job->timeout);
        $serialized = serialize($job);
        $this->assertStringContainsString($job->analysisRunId, $serialized);
        foreach (['http', 'X-Amz', 'minio', 'source.zip', FakeAnalyzer::SECRET] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_a_queued_run_is_analyzed_verified_and_stored(): void
    {
        foreach (AnalysisResultType::cases() as $type) {
            $run = $this->queuedRun($type);
            $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::success($r));

            $this->runJob($this->newJob($run))->assertNotReleased();

            $run->refresh();
            $this->assertSame(AnalysisRunStatus::Succeeded, $run->status);
            $this->assertNotNull($run->started_at);
            $this->assertNotNull($run->completed_at);
            $stored = AnalysisResult::query()->findOrFail($run->id);
            $this->assertSame($run->result_hash, $stored->result_hash);
            $this->assertSame($type, $stored->result_type);
            $this->assertSame($type->value, $stored->decoded()->result_type);
            $this->assertSame($run->id, $stored->decoded()->analysis_run_id);
            // Stored as verified: it still hashes to its result_hash (floats such as 1.0 intact).
            $hashed = $stored->decoded();
            unset($hashed->request_id, $hashed->diagnostics, $hashed->result_hash);
            $this->assertSame($run->result_hash, CanonicalJson::hash($hashed));
            $this->assertSame($type === AnalysisResultType::Foundation ? null : '1.0', $run->metrics_version);
            $this->assertNull($run->scoring_version);
            // Phase 11: a static_analysis result is scored after it is stored (tests/Feature/Dna).
            $this->assertSame($type === AnalysisResultType::StaticAnalysis ? 1 : 0, $run->dnaSnapshots()->count());
            $this->assertCount(1, $run->metadata['attempts']);
            $this->assertArrayNotHasKey('lease', $run->metadata);
        }
    }

    public function test_successful_results_are_immutable(): void
    {
        $run = $this->queuedRun();
        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::success($r));
        $this->runJob($this->newJob($run));
        $stored = AnalysisResult::query()->findOrFail($run->id);

        foreach ([fn () => $stored->forceFill(['result_hash' => str_repeat('0', 64)])->save(), fn () => $stored->delete(),
            fn () => $run->refresh()->markFailed('ANALYSIS_FAILED'), fn () => $run->refresh()->markRunning()] as $change) {
            try {
                $change();
                $this->fail('A successful result changed');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_running_the_job_again_after_success_does_nothing(): void
    {
        $run = $this->queuedRun();
        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::success($r));
        $this->runJob($this->newJob($run));
        $hash = $run->refresh()->result_hash;

        $this->runJob($this->newJob($run));
        $this->runJob($this->newJob($run));

        Http::assertSentCount(1);
        $this->assertSame($hash, $run->refresh()->result_hash);
        $this->assertSame(1, AnalysisResult::query()->where('analysis_run_id', $run->id)->count());
    }

    public function test_a_second_job_cannot_take_over_a_live_lease(): void
    {
        $run = $this->queuedRun();
        $first = $this->newJob($run);
        // The first job claimed the run and is still working on it.
        $this->fakeAnalyzer(fn () => throw new ConnectionException('cURL error 7: refused'));
        $this->runJob($first)->assertReleased(30);
        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::success($r));

        $this->runJob($this->newJob($run));

        $this->assertSame([], FakeAnalyzer::$requests, 'the second job never called the analyzer');
        $this->assertSame(AnalysisRunStatus::Running, $run->refresh()->status);
        $this->assertCount(1, $run->metadata['attempts']);
    }

    public function test_an_expired_lease_is_taken_over(): void
    {
        $run = $this->queuedRun();
        $this->fakeAnalyzer(fn () => throw new ConnectionException('cURL error 7: refused'));
        $this->runJob($this->newJob($run));

        Carbon::setTestNow(Carbon::now()->addSeconds(1000));
        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::success($r));
        $this->runJob($this->newJob($run));
        Carbon::setTestNow();

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->refresh()->status);
        $this->assertCount(2, $run->metadata['attempts']);
    }

    public function test_transient_failures_are_retried_with_backoff_then_fail(): void
    {
        $run = $this->queuedRun();
        $job = new AnalyzeSourceSnapshot($run->id);
        $sentAttempts = [];
        $this->fakeAnalyzer(function (Request $r) use (&$sentAttempts) {
            $sentAttempts[] = json_decode($r->body(), true)['attempt'];

            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased(30);
        $this->assertSame(AnalysisRunStatus::Running, $run->refresh()->status);
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased(120);
        $this->assertSame(AnalysisRunStatus::Running, $run->refresh()->status);
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertNotReleased();

        $run->refresh();
        $this->assertSame(AnalysisRunStatus::Failed, $run->status);
        $this->assertSame('ANALYZER_UNAVAILABLE', $run->failure_code);
        $this->assertSame('The analysis service is temporarily unavailable.', $run->failure_message);
        $this->assertSame([1, 2, 3], array_column($run->metadata['attempts'], 'attempt'));
        $this->assertCount(3, array_unique(array_column($run->metadata['attempts'], 'request_id')), 'a new request ID per attempt');
        $this->assertSame([1, 2, 3], $sentAttempts, 'the analyzer saw attempts 1, 2 and 3');
    }

    public function test_retry_after_is_honoured_and_an_expired_source_url_is_replaced_at_once(): void
    {
        $run = $this->queuedRun();
        $job = new AnalyzeSourceSnapshot($run->id);

        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::error($r, 503, 'ANALYZER_BUSY', true, ['Retry-After' => '90']));
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased(90);

        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::error($r, 422, 'SOURCE_URL_EXPIRED', true));
        $this->runJob((clone $job)->withFakeQueueInteractions())->assertReleased(1);

        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::success($r));
        $this->runJob((clone $job)->withFakeQueueInteractions());

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->refresh()->status);
        $urls = array_map(fn (array $request) => $request['body']['source']['url'], FakeAnalyzer::$requests);
        $this->assertCount(3, $urls);
        foreach ($urls as $url) {
            $this->assertStringContainsString('X-Amz-Signature=', $url, 'every attempt signs its own URL');
        }
    }

    public function test_integrity_and_security_failures_are_never_retried(): void
    {
        foreach ([
            'ANALYZER_RESULT_HASH_MISMATCH' => fn (Request $r) => FakeAnalyzer::success($r, null, fn ($x) => $x->result_hash = str_repeat('a', 64)),
            'ANALYZER_AUTH_FAILED' => fn (Request $r) => FakeAnalyzer::signed($r, 200, '{}', [], str_repeat('x', 64)),
            'ANALYZER_RESULT_INVALID' => fn (Request $r) => FakeAnalyzer::success($r, fn ($x) => $x->analysis_run_id = '01k6p0a1b2c3d4e5f6g7h8j9km'),
            'ANALYZER_INVALID_RESPONSE' => fn (Request $r) => FakeAnalyzer::signed($r, 200, 'not json'),
            'INVALID_ARCHIVE' => fn (Request $r) => FakeAnalyzer::error($r, 422, 'INVALID_ARCHIVE', false),
            'ANALYSIS_TIMEOUT' => fn (Request $r) => FakeAnalyzer::error($r, 504, 'ANALYSIS_TIMEOUT', false),
        ] as $code => $handler) {
            $run = $this->queuedRun();
            $this->fakeAnalyzer($handler);

            $this->runJob($this->newJob($run))->assertNotReleased();

            $run->refresh();
            $this->assertSame(AnalysisRunStatus::Failed, $run->status, $code);
            $this->assertSame($code, $run->failure_code);
            $this->assertNull($run->result_hash);
            $this->assertNull(AnalysisResult::query()->find($run->id), 'nothing unverified is stored');
        }
    }

    public function test_an_unexpected_error_fails_the_run_safely(): void
    {
        $run = $this->queuedRun();
        $this->fakeAnalyzer(fn () => throw new RuntimeException('secret detail /var/www/x'));

        $this->runJob($this->newJob($run))->assertNotReleased();

        $run->refresh();
        $this->assertSame('ANALYSIS_FAILED', $run->failure_code);
        $this->assertSame('The analysis failed.', $run->failure_message);
    }

    public function test_a_killed_job_fails_its_run(): void
    {
        $run = $this->queuedRun();
        $this->fakeAnalyzer(fn () => throw new ConnectionException('cURL error 7: refused'));
        $job = $this->runJob($this->newJob($run));

        $job->failed(new TimeoutExceededException('timed out'));

        $this->assertSame('ANALYZER_TIMEOUT', $run->refresh()->failure_code);
    }

    public function test_a_result_for_a_run_that_ended_meanwhile_is_discarded(): void
    {
        $run = $this->queuedRun();
        $this->fakeAnalyzer(function (Request $r) use ($run) {
            // The stale sweeper (or a cancel) ends the run while the analyzer works.
            DB::table('analysis_runs')->where('id', $run->id)->update([
                'status' => 'CANCELLED', 'completed_at' => now(),
            ]);

            return FakeAnalyzer::success($r);
        });

        $this->runJob($this->newJob($run));

        $this->assertSame(AnalysisRunStatus::Cancelled, $run->refresh()->status);
        $this->assertNull(AnalysisResult::query()->find($run->id));
    }

    public function test_attempts_are_bounded_across_redeliveries(): void
    {
        $run = AnalysisRun::factory()->running()->create([
            'metadata' => ['attempts' => [['attempt' => 1], ['attempt' => 2], ['attempt' => 3]]],
        ]);

        $this->runJob($this->newJob($run));

        Http::assertNothingSent();
        $this->assertSame('ANALYZER_UNAVAILABLE', $run->refresh()->failure_code);
    }

    public function test_logs_carry_ids_but_never_urls_secrets_or_bodies(): void
    {
        $run = $this->queuedRun();
        $job = new AnalyzeSourceSnapshot($run->id);
        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::error($r, 503, 'ANALYZER_BUSY', true));
        $this->runJob((clone $job)->withFakeQueueInteractions());
        $this->fakeAnalyzer(fn (Request $r) => FakeAnalyzer::success($r));
        $this->runJob((clone $job)->withFakeQueueInteractions());

        $messages = array_map(fn (MessageLogged $log) => $log->message, $this->logs);
        $this->assertContains('analysis.started', $messages);
        $this->assertContains('analysis.retrying', $messages);
        $this->assertContains('analysis.completed', $messages);

        $logged = json_encode(array_map(fn (MessageLogged $log) => [$log->message, $log->context], $this->logs));
        $this->assertStringContainsString($run->id, (string) $logged);
        foreach (['X-Amz', 'http://', 'minio', FakeAnalyzer::SECRET, 'v1=', 'Café', 'ir'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden === 'ir' ? '"ir":' : $forbidden, (string) $logged);
        }
    }

    public function test_the_stale_sweeper_fails_stuck_runs_only(): void
    {
        $stuck = AnalysisRun::factory()->running()->create();
        DB::table('analysis_runs')->where('id', $stuck->id)->update(['updated_at' => now()->subHour()]);
        $lost = AnalysisRun::factory()->create();
        DB::table('analysis_runs')->where('id', $lost->id)->update(['updated_at' => now()->subDays(2)]);
        $active = AnalysisRun::factory()->running()->create(['result_type' => 'static_analysis']);
        $done = AnalysisRun::factory()->succeeded()->create();

        $this->artisan('analysis:fail-stale')->assertSuccessful();

        $this->assertSame('ANALYSIS_STALE', $stuck->refresh()->failure_code);
        $this->assertSame('ANALYSIS_STALE', $lost->refresh()->failure_code);
        $this->assertSame(AnalysisRunStatus::Running, $active->refresh()->status);
        $this->assertSame(AnalysisRunStatus::Succeeded, $done->refresh()->status);
    }
}
