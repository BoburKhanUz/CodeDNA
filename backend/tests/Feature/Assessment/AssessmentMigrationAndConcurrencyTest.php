<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Actions\Assessment\RequestAssessment;
use App\Jobs\GenerateAssessment;
use App\Models\AiAssessment;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentPrompt;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Assessment\Provider\AiProviderResponse;
use App\Services\Assessment\Provider\FakeAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AssessmentFixtures;
use Tests\Support\BillingFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Committed-data tests (no RefreshDatabase transaction): concurrent
 * requests and concurrent jobs in forked processes, and the Phase 15
 * migration's rollback and re-application. Everything created is deleted.
 */
final class AssessmentMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_12_000001_create_ai_assessments_table.php';

    private const CHALLENGE_MIGRATION = 'database/migrations/2026_10_13_000001_create_challenge_tables.php';

    private const ROADMAP_MIGRATION = 'database/migrations/2026_10_14_000001_create_roadmap_tables.php';

    private const GROWTH_MIGRATION = 'database/migrations/2026_10_15_000001_create_growth_tables.php';

    private ?SkillGapSnapshot $gaps = null;

    private string $resultDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->resultDir = sys_get_temp_dir().'/codedna-assessment-'.Str::random(8);
        mkdir($this->resultDir);
        config(['codedna.ai.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        if ($this->gaps !== null) {
            $projectId = $this->gaps->project_id;
            DB::table('ai_assessments')->where('project_id', $projectId)->delete();
            DB::table('skill_gap_results')->where('project_id', $projectId)->delete();
            DB::table('skill_gap_snapshots')->where('project_id', $projectId)->delete();
            DB::table('competency_snapshots')->where('project_id', $projectId)->delete();
            DB::table('dna_snapshots')->where('project_id', $projectId)->delete();
            DB::table('analysis_results')->where('analysis_run_id', $this->gaps->analysis_run_id)->delete();
            DB::table('analysis_runs')->where('project_id', $projectId)->delete();
            DB::table('source_snapshots')->where('project_id', $projectId)->delete();
            DB::table('projects')->where('id', $projectId)->delete();
            BillingFixtures::forget([(string) $this->gaps->user_id]);
            DB::table('users')->where('id', $this->gaps->user_id)->delete();
        }
        foreach (glob($this->resultDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->resultDir);
        parent::tearDown();
    }

    private function gaps(): SkillGapSnapshot
    {
        return $this->gaps = AssessmentFixtures::skillGaps(Project::factory()->for(BillingFixtures::pro(User::factory()->create()))->create());
    }

    /**
     * Runs $work in $count forked processes released at the same instant;
     * returns what each wrote.
     *
     * @param  callable(int): string  $work
     * @return list<string>
     */
    private function concurrently(int $count, callable $work): array
    {
        DB::disconnect();
        $startAt = microtime(true) + 0.5;
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    time_sleep_until($startAt);
                    $out = $work($i);
                } catch (Throwable $e) {
                    $out = 'error: '.$e::class.': '.$e->getMessage();
                }
                file_put_contents("{$this->resultDir}/{$i}", $out);
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = (string) @file_get_contents("{$this->resultDir}/{$i}");
        }

        return $results;
    }

    /**
     * A provider that records every call in a file (shared across forks)
     * and takes a moment to answer.
     */
    private function countingProvider(): AiProvider
    {
        $dir = $this->resultDir;

        return new class($dir) implements AiProvider
        {
            public function __construct(private readonly string $dir) {}

            public function name(): string
            {
                return 'counting';
            }

            public function model(): string
            {
                return 'counting-model';
            }

            public function generateAssessment(AssessmentInput $input, AssessmentPrompt $prompt): AiProviderResponse
            {
                file_put_contents($this->dir.'/calls', "call\n", FILE_APPEND | LOCK_EX);
                usleep(300_000);

                return (new FakeAiProvider)->generateAssessment($input, $prompt);
            }
        };
    }

    public function test_concurrent_requests_create_exactly_one_assessment_and_one_job(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        Bus::fake();
        $this->app->instance(AiProvider::class, $this->countingProvider());
        $projectId = $this->gaps()->project_id;

        $results = $this->concurrently(8, function () use ($projectId): string {
            $project = Project::query()->findOrFail($projectId);
            $requested = app(RequestAssessment::class)->handle($project, $project->user()->firstOrFail(), null);

            return $requested->assessment->id.'|'.($requested->created ? 'created' : 'existing');
        });

        foreach ($results as $result) {
            $this->assertMatchesRegularExpression('/^[0-9a-z]{26}\|(created|existing)$/', $result, "a request failed: {$result}");
        }
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode('|', $r)[0], $results)));
        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_ends_with($r, '|created')));
        $this->assertSame(1, AiAssessment::query()->where('project_id', $projectId)->count());
        $this->assertFileDoesNotExist($this->resultDir.'/calls', 'requests never call the provider');
    }

    /**
     * Duplicate jobs for one assessment race for the claim: exactly one
     * provider call.
     */
    public function test_concurrent_jobs_make_exactly_one_provider_call(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        Bus::fake();
        $this->app->instance(AiProvider::class, $this->countingProvider());
        $gaps = $this->gaps();
        $project = Project::query()->findOrFail($gaps->project_id);
        $assessmentId = app(RequestAssessment::class)->handle($project, $project->user()->firstOrFail(), null)->assessment->id;

        $results = $this->concurrently(6, function () use ($assessmentId): string {
            app()->call([new GenerateAssessment($assessmentId), 'handle']);

            return 'done';
        });

        $this->assertSame(array_fill(0, 6, 'done'), $results);
        $this->assertSame(1, substr_count((string) file_get_contents($this->resultDir.'/calls'), 'call'));
        $assessment = AiAssessment::query()->findOrFail($assessmentId);
        $this->assertSame(['SUCCEEDED', 1], [$assessment->status->value, $assessment->attempts]);
    }

    public function test_constraints_rollback_and_reapplication(): void
    {
        $constraints = fn (string $table): array => array_map(fn (object $r): string => $r->conname, DB::select(
            'select conname from pg_constraint where conrelid = ?::regclass order by conname', [$table],
        ));
        foreach (['ai_assessments_lineage_foreign', 'ai_assessments_status_valid', 'ai_assessments_fingerprints_sha256',
            'ai_assessments_output_iff_succeeded', 'ai_assessments_failure_iff_failed', 'ai_assessments_completed_iff_terminal',
            'ai_assessments_lease_iff_running', 'ai_assessments_provider_format'] as $name) {
            $this->assertContains($name, $constraints('ai_assessments'));
        }
        $this->assertContains('skill_gap_snapshots_lineage_unique', $constraints('skill_gap_snapshots'));
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_constraint where conrelid = 'ai_assessments'::regclass and contype = 'f' and confdeltype <> 'r'"), 'no cascades');
        $this->assertSame(1, (int) DB::scalar("select count(*) from pg_indexes where indexname = 'ai_assessments_identity_active_unique'"));
        $this->assertSame(1, (int) DB::scalar("select count(*) from pg_trigger where tgname = 'ai_assessments_terminal_immutable'"));

        $gaps = $this->gaps();
        // reset, not rollback: it reverts the growth and roadmap migrations whatever batch they are in.
        // Phase 29 AI insights reference these tables: they are reverted first.
        $this->artisan('migrate:reset', ['--path' => 'database/migrations/2026_10_21_000001_create_ai_insights_table.php'])->assertSuccessful();
        $this->artisan('migrate:reset', ['--path' => self::GROWTH_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:reset', ['--path' => self::ROADMAP_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:reset', ['--path' => self::CHALLENGE_MIGRATION])->assertSuccessful();
        $this->artisan('migrate:reset', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('ai_assessments'));
        $this->assertNotContains('skill_gap_snapshots_lineage_unique', $constraints('skill_gap_snapshots'));
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_proc where proname = 'ai_assessments_refuse_terminal_update'"));
        $this->assertSame(1, DB::table('skill_gap_snapshots')->where('id', $gaps->id)->count(), 'skill gap history is untouched');

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('ai_assessments'));
        Bus::fake();
        $project = Project::query()->findOrFail($gaps->project_id);
        $this->app->instance(AiProvider::class, $this->countingProvider());
        $this->assertTrue(app(RequestAssessment::class)->handle($project, $project->user()->firstOrFail(), null)->created, 'existing snapshots can be assessed after the upgrade');
    }
}
