<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisRunStatus;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisRun;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeAnalyzer;
use Tests\TestCase;

/**
 * Phase 26 (docs/performance/queue-performance.md#analyzer-slots): workers
 * call the analyzer only while holding one of its slots. Before this, more
 * workers than analyzer slots turned bursts into ANALYZER_BUSY answers that
 * used up attempts and failed analyses (measured: 18 of 24 with 4 workers
 * and 2 slots).
 */
final class AnalyzerSlotsTest extends TestCase
{
    use RefreshDatabase;

    private int $calls = 0;

    private ?Closure $handler = null;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAnalyzer::configure();
        // Redis database 14 is reserved for tests (phpunit.xml).
        Redis::connection()->command('flushdb');
        config(['codedna.analyzer.max_concurrency' => 1, 'codedna.analyzer.slot_wait_seconds' => 1]);
        Http::fake(['http://analyzer:8000/*' => function (Request $r) {
            $this->calls++;

            return ($this->handler ?? fn (Request $r) => FakeAnalyzer::success($r))($r);
        }]);
    }

    protected function tearDown(): void
    {
        Redis::connection()->command('flushdb');
        parent::tearDown();
    }

    private function work(AnalysisRun $run): void
    {
        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);
    }

    /** Runs $work while another worker holds every analyzer slot. */
    private function whileSlotsAreBusy(Closure $work): void
    {
        Redis::funnel('codedna:analyzer-slots')->limit(1)->releaseAfter(60)->block(0)->then($work);
    }

    public function test_a_worker_without_a_free_slot_hands_the_run_over_without_using_an_attempt(): void
    {
        Queue::fake();
        $run = AnalysisRun::factory()->create();

        $this->whileSlotsAreBusy(fn () => $this->work($run));

        $this->assertSame(0, $this->calls, 'the analyzer is not asked while its slots are busy');
        $run->refresh();
        $this->assertSame(AnalysisRunStatus::Queued, $run->status);
        $this->assertArrayNotHasKey('attempts', $run->metadata ?? [], 'no attempt is counted');
        Queue::assertPushed(AnalyzeSourceSnapshot::class, fn (AnalyzeSourceSnapshot $job): bool => $job->analysisRunId === $run->id && $job->delay !== null);
    }

    public function test_the_deferred_run_completes_once_a_slot_is_free(): void
    {
        Queue::fake();
        $run = AnalysisRun::factory()->create();
        $this->whileSlotsAreBusy(fn () => $this->work($run));

        $this->work($run);

        $this->assertSame(1, $this->calls);
        $this->assertSame(AnalysisRunStatus::Succeeded, $run->refresh()->status);
        $this->assertCount(1, $run->metadata['attempts']);
    }

    public function test_the_slot_is_released_after_the_analyzer_answers_even_with_an_error(): void
    {
        $this->handler = fn (Request $r) => FakeAnalyzer::error($r, 503, 'ANALYZER_BUSY', true);
        $failing = AnalysisRun::factory()->create();
        $this->work($failing);
        $this->assertSame(1, $this->calls);

        $this->handler = null;
        $next = AnalysisRun::factory()->create();
        $this->work($next);

        $this->assertSame(2, $this->calls, 'the single slot was free again');
        $this->assertSame(AnalysisRunStatus::Succeeded, $next->refresh()->status);
    }

    public function test_slots_are_bounded_by_the_configured_analyzer_concurrency(): void
    {
        config(['codedna.analyzer.max_concurrency' => 2]);
        Queue::fake();
        $run = AnalysisRun::factory()->create();

        // One slot of two is busy: the worker gets the other one.
        Redis::funnel('codedna:analyzer-slots')->limit(2)->releaseAfter(60)->block(0)->then(fn () => $this->work($run));

        $this->assertSame(1, $this->calls);
        $this->assertSame(AnalysisRunStatus::Succeeded, $run->refresh()->status);
        Queue::assertNothingPushed();
    }
}
