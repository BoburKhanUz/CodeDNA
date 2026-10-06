<?php

declare(strict_types=1);

namespace Tests\Feature\Dna;

use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Enums\DnaSnapshotStatus;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisRun;
use App\Models\DnaSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use stdClass;
use Tests\Support\FakeAnalyzer;
use Tests\Support\StoredResults;
use Tests\TestCase;

/**
 * How scoring is triggered: by the analysis job after a static_analysis
 * result is stored, and by `php artisan dna:score`.
 */
final class DnaScoringPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logs = [];

    /** @var (callable(stdClass): void)|null */
    private $mutate = null;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAnalyzer::configure();
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r, $this->mutate)]);
    }

    private function analyze(AnalysisResultType $type): AnalysisRun
    {
        $run = AnalysisRun::factory()->create(['result_type' => $type]);
        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);

        return $run->refresh();
    }

    /**
     * @return list<string>
     */
    private function logged(string $message): array
    {
        return array_values(array_map(fn (MessageLogged $l): string => $l->level, array_filter($this->logs, fn (MessageLogged $l): bool => $l->message === $message)));
    }

    public function test_a_successful_static_analysis_is_scored_once_by_the_job(): void
    {
        $this->mutate = CalculateDnaSnapshotTest::rateable(...);

        $run = $this->analyze(AnalysisResultType::StaticAnalysis);

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->status);
        $dna = $run->dnaSnapshots()->sole();
        $this->assertSame(DnaSnapshotStatus::Ready, $dna->status);
        $this->assertSame('0.8050', $dna->overall_score);
        $this->assertSame($run->result_hash, $dna->result_hash);
        $this->assertSame(['info'], $this->logged('dna.scored'));
        // The run itself is unchanged by scoring (it is immutable once SUCCEEDED).
        $this->assertNull($run->scoring_version);
    }

    public function test_foundation_results_are_not_scored(): void
    {
        $run = $this->analyze(AnalysisResultType::Foundation);

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->status);
        $this->assertSame(0, DnaSnapshot::query()->count());
        $this->assertSame([], $this->logged('dna.scored'));
        $this->assertSame([], $this->logged('dna.scoring_failed'), 'foundation results are not even attempted');
    }

    public function test_a_scoring_failure_never_fails_the_analysis(): void
    {
        // Valid against the contract schema, but inconsistent: more long functions than functions.
        $this->mutate = function (stdClass $result): void {
            CalculateDnaSnapshotTest::rateable($result);
            $result->findings->by_rule->{'structure/function-length'} = 41;
        };

        $run = $this->analyze(AnalysisResultType::StaticAnalysis);

        $this->assertSame(AnalysisRunStatus::Succeeded, $run->status);
        $this->assertSame(0, DnaSnapshot::query()->count());
        $this->assertSame(['warning'], $this->logged('dna.scoring_failed'));
        $failure = array_values(array_filter($this->logs, fn (MessageLogged $l): bool => $l->message === 'dna.scoring_failed'))[0];
        $this->assertSame('RESULT_INVALID', $failure->context['error_code']);
    }

    public function test_the_command_scores_unscored_runs_idempotently(): void
    {
        $runs = [
            StoredResults::succeededRun('static_analysis', CalculateDnaSnapshotTest::rateable(...)),
            StoredResults::succeededRun(),
        ];
        StoredResults::succeededRun('foundation');
        AnalysisRun::factory()->failed()->create(['result_type' => 'static_analysis']);

        $this->artisan('dna:score', ['--missing' => true])
            ->expectsOutputToContain('Scoring version 1.0.0: 2 run(s), 0 failed.')
            ->assertSuccessful();
        $this->assertSame(2, DnaSnapshot::query()->count());
        $this->assertSame([DnaSnapshotStatus::Ready, DnaSnapshotStatus::InsufficientData], [
            $runs[0]->dnaSnapshots()->sole()->status, $runs[1]->dnaSnapshots()->sole()->status,
        ]);

        $this->artisan('dna:score', ['--missing' => true])->expectsOutputToContain('0 run(s)')->assertSuccessful();
        $this->artisan('dna:score', ['analysis_run' => $runs[0]->id])->expectsOutputToContain('exists READY')->assertSuccessful();
        $this->assertSame(2, DnaSnapshot::query()->count());
    }

    public function test_the_command_reports_runs_that_cannot_be_scored(): void
    {
        $failed = AnalysisRun::factory()->failed()->create(['result_type' => 'static_analysis']);

        $this->artisan('dna:score', ['analysis_run' => $failed->id])->expectsOutputToContain('failed RUN_NOT_SUCCEEDED')->assertFailed();
        $this->artisan('dna:score')->assertExitCode(2);
        $this->assertSame(0, DnaSnapshot::query()->count());
    }
}
