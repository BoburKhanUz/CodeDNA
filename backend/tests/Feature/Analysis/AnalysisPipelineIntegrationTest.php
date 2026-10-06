<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Actions\Analysis\StartAnalysis;
use App\Actions\Snapshots\StoreUploadedSource;
use App\Enums\AnalysisResultType;
use App\Enums\AnalysisRunStatus;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Analyzer\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ZipBuilder;
use Tests\TestCase;

/**
 * The whole pipeline with real services: Laravel + PostgreSQL + Redis (the
 * analysis queue and a real worker) + MinIO (pre-signed URL) + the analyzer
 * container (HMAC both ways, foundation and static_analysis results, hash
 * verification). Requires the development stack (`make up`).
 *
 * Rows are committed (the worker runs them through the queue), so this test
 * does not use RefreshDatabase; it deletes what it created, including the
 * stored objects and the queue keys.
 */
final class AnalysisPipelineIntegrationTest extends TestCase
{
    private string $prefix;

    private string $queue;

    private ?Project $project = null;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $secret = (string) getenv('ANALYZER_HMAC_SECRET');
        if (strlen($secret) < 32) {
            $this->markTestSkipped('The analyzer integration test needs the development stack (ANALYZER_HMAC_SECRET).');
        }
        $this->prefix = 'phpunit/'.strtolower((string) Str::ulid()).'/';
        $this->queue = 'phpunit-analysis-'.Str::lower(Str::random(10));
        config([
            'codedna.sources.key_prefix' => $this->prefix,
            'codedna.analysis.queue' => $this->queue,
            'codedna.analyzer.hmac_secret' => $secret,
            'codedna.analyzer.hmac_secret_previous' => '',
            'codedna.analyzer.url' => 'http://analyzer:8000',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->project !== null) {
            $runs = DB::table('analysis_runs')->where('project_id', $this->project->id)->pluck('id');
            DB::table('analysis_results')->whereIn('analysis_run_id', $runs)->delete();
            DB::table('analysis_runs')->where('project_id', $this->project->id)->delete();
            DB::table('source_snapshots')->where('project_id', $this->project->id)->delete();
            DB::table('projects')->where('id', $this->project->id)->delete();
            DB::table('users')->where('id', $this->project->user_id)->delete();
        }
        Storage::forgetDisk('sources');
        Storage::disk('sources')->deleteDirectory(rtrim($this->prefix, '/'));
        foreach (['', ':reserved', ':delayed', ':notify'] as $suffix) {
            Redis::connection()->del('queues:'.$this->queue.$suffix);
        }
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function uploadedSnapshot(): SourceSnapshot
    {
        $this->project = Project::factory()->create();
        $user = User::query()->findOrFail($this->project->user_id);
        $path = $this->tempFiles[] = (new ZipBuilder)
            ->file('src/Invoice.php', "<?php\nfinal class Invoice extends Model\n{\n    public function total(array \$lines): int\n    {\n        return \$lines ? 1 : 0;\n    }\n}\n")
            ->file('src/tool.py', "def run(a, b):\n    if a and b:\n        return 1\n    return 0\n")
            ->file('README.md', "# integration\n")
            ->save();

        return app(StoreUploadedSource::class)->handle($this->project, $user, $path)->snapshot;
    }

    private function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'analysis',
            '--queue' => $this->queue,
            '--once' => true,
            '--stop-when-empty' => true,
        ])->assertSuccessful();
    }

    public function test_both_result_types_run_through_redis_minio_and_the_real_analyzer(): void
    {
        $snapshot = $this->uploadedSnapshot();
        $user = User::query()->findOrFail($snapshot->project->user_id);

        foreach (AnalysisResultType::cases() as $type) {
            $started = app(StartAnalysis::class)->handle($snapshot->project, $user, $snapshot->id, $type);
            $this->assertTrue($started->created);

            // The queued payload: the run ID, nothing else of interest.
            $payloads = Redis::connection()->lrange('queues:'.$this->queue, 0, -1);
            $this->assertCount(1, $payloads);
            $this->assertStringContainsString($started->run->id, $payloads[0]);
            foreach (['X-Amz', 'http://', 'minio', $snapshot->storage_key, $snapshot->source_hash, (string) config('codedna.analyzer.hmac_secret')] as $secret) {
                $this->assertStringNotContainsString(str_replace('/', '\/', $secret), $payloads[0]);
                $this->assertStringNotContainsString($secret, $payloads[0]);
            }
            $this->assertStringContainsString(AnalyzeSourceSnapshot::class, json_decode($payloads[0], true)['displayName']);

            $this->work();

            $run = AnalysisRun::query()->findOrFail($started->run->id);
            $this->assertSame(AnalysisRunStatus::Succeeded, $run->status, (string) $run->failure_code);
            $this->assertSame($run->result_hash, AnalysisResult::query()->findOrFail($run->id)->result_hash);
            // Re-verify from the stored JSONB, decoded to objects (as the client does).
            $result = AnalysisResult::query()->findOrFail($run->id)->decoded();
            $this->assertSame($type->value, $result->result_type);
            $this->assertSame($run->id, $result->analysis_run_id);
            $this->assertSame($snapshot->source_hash, $result->source->sha256);
            $this->assertSame(3, $result->source->files_total);
            $this->assertSame($type === AnalysisResultType::Foundation ? '1.0' : '1.1', $run->ir_version);

            // The stored result still hashes to its result_hash: it can be re-verified later.
            $hashed = clone $result;
            unset($hashed->request_id, $hashed->diagnostics, $hashed->result_hash);
            $this->assertSame($run->result_hash, CanonicalJson::hash($hashed));
            if ($type === AnalysisResultType::StaticAnalysis) {
                $this->assertSame(2, $result->parsing->files->PARSED);
                $this->assertSame(1, $result->metrics->overall->types);
            }
        }

        $this->assertSame(0, Redis::connection()->llen('queues:'.$this->queue), 'no job left behind');
    }

    public function test_a_wrong_secret_is_rejected_by_the_real_analyzer_and_not_retried(): void
    {
        $snapshot = $this->uploadedSnapshot();
        $run = AnalysisRun::factory()->for($snapshot)->create(['result_type' => 'static_analysis']);
        config(['codedna.analyzer.hmac_secret' => str_repeat('w', 64)]);

        app()->call([(new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions(), 'handle']);

        $run->refresh();
        $this->assertSame(AnalysisRunStatus::Failed, $run->status);
        $this->assertSame('ANALYZER_AUTH_FAILED', $run->failure_code);
        $this->assertCount(1, $run->metadata['attempts']);
    }

    public function test_an_unreachable_analyzer_is_a_retryable_failure(): void
    {
        $snapshot = $this->uploadedSnapshot();
        $run = AnalysisRun::factory()->for($snapshot)->create();
        config(['codedna.analyzer.url' => 'http://analyzer:1', 'codedna.analyzer.connect_timeout_seconds' => 2]);

        $job = (new AnalyzeSourceSnapshot($run->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(30);
        $this->assertSame(AnalysisRunStatus::Running, $run->refresh()->status);
        $this->assertSame('ANALYZER_UNAVAILABLE', $run->metadata['attempts'][0]['error_code']);
    }
}
