<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Actions\Insights\RequestInsight;
use App\Enums\Insights\InsightKind;
use App\Jobs\GenerateInsight;
use App\Models\AiInsight;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Ai\FakeModelClient;
use App\Services\Ai\ModelClient;
use App\Services\Ai\ModelHealth;
use App\Services\Assessment\Provider\AiProviderResponse;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BillingFixtures;
use Tests\Support\InsightFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Committed-data tests (no RefreshDatabase transaction) for AI insights
 * (Phase 29): concurrent requests and duplicate job deliveries in forked
 * processes against real PostgreSQL and Redis, and the migration's
 * rollback and re-application. Everything created is deleted.
 */
final class InsightMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_21_000001_create_ai_insights_table.php';

    private const TABLES = [
        'ai_insights', 'growth_observations', 'growth_snapshots', 'roadmap_step_completions', 'roadmap_steps', 'roadmap_snapshots',
        'challenge_submissions', 'challenge_instances', 'skill_gap_results', 'skill_gap_snapshots', 'competency_snapshots', 'dna_snapshots',
    ];

    private ?Project $project = null;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->dir = sys_get_temp_dir().'/codedna-insights-'.Str::random(8);
        mkdir($this->dir);
        Queue::fake();
        config(['codedna.ai.enabled' => true, 'codedna.ai.provider' => 'ollama', 'codedna.challenges.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        if ($this->project !== null) {
            $id = $this->project->id;
            foreach (self::TABLES as $table) {
                DB::table($table)->where('project_id', $id)->delete();
            }
            $runs = DB::table('analysis_runs')->where('project_id', $id)->pluck('id');
            DB::table('analysis_results')->whereIn('analysis_run_id', $runs)->delete();
            DB::table('analysis_runs')->where('project_id', $id)->delete();
            DB::table('source_snapshots')->where('project_id', $id)->delete();
            DB::table('projects')->where('id', $id)->delete();
            BillingFixtures::forget([(string) $this->project->user_id]);
            DB::table('users')->where('id', $this->project->user_id)->delete();
        }
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function project(): Project
    {
        return $this->project = Project::factory()->for(BillingFixtures::pro(User::factory()->create()))->create();
    }

    /**
     * @param  callable(int): string  $work
     * @return list<string>
     */
    private function concurrently(int $count, callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        DB::disconnect();
        $startAt = microtime(true) + 0.5;
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::purge();
                    time_sleep_until($startAt);
                    $out = $work($i);
                } catch (Throwable $e) {
                    $out = 'error: '.$e::class.': '.$e->getMessage();
                }
                file_put_contents("{$this->dir}/{$i}", $out);
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        return array_map(fn (int $i): string => (string) @file_get_contents("{$this->dir}/{$i}"), range(0, $count - 1));
    }

    /** A model that records each call in a file shared across forks and takes a moment. */
    private function countingModel(): ModelClient
    {
        $dir = $this->dir;

        return new class($dir) implements ModelClient
        {
            public function __construct(private readonly string $dir) {}

            public function name(): string
            {
                return 'ollama';
            }

            public function model(): string
            {
                return 'counting:1b';
            }

            public function health(): ModelHealth
            {
                return new ModelHealth(true, true);
            }

            public function complete(AiRequest $request): AiProviderResponse
            {
                file_put_contents($this->dir.'/calls', "x\n", FILE_APPEND | LOCK_EX);
                usleep(300000);

                return (new FakeModelClient)->complete($request);
            }
        };
    }

    public function test_concurrent_requests_create_one_insight_and_charge_once(): void
    {
        $this->app->instance(ModelClient::class, $this->countingModel());
        $project = $this->project();
        $owner = User::query()->findOrFail($project->user_id);
        $growth = InsightFixtures::growth($project);

        $results = $this->concurrently(8, fn (): string => ($r = app(RequestInsight::class)->handle($project, $owner, InsightKind::GrowthInterpretation, $growth->id))->created
            ? 'created:'.$r->insight->id : 'existing:'.$r->insight->id);

        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_starts_with($r, 'created:')), implode("\n", $results));
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode(':', $r)[1] ?? $r, $results)), implode("\n", $results));
        $this->assertSame(1, AiInsight::query()->where('project_id', $project->id)->count());
        $this->assertSame(1, (int) DB::table('billing_usage_counters')->where('user_id', $owner->id)->where('quota_key', 'AI_ASSESSMENTS')->value('used'));
    }

    public function test_duplicate_deliveries_make_one_model_call(): void
    {
        $this->app->instance(ModelClient::class, $this->countingModel());
        $project = $this->project();
        $owner = User::query()->findOrFail($project->user_id);
        $insight = app(RequestInsight::class)->handle($project, $owner, InsightKind::RoadmapGuidance, InsightFixtures::roadmap($project, $owner)->id)->insight;

        $this->concurrently(6, function () use ($insight): string {
            app()->call([(new GenerateInsight($insight->id))->withFakeQueueInteractions(), 'handle']);

            return 'done';
        });

        $this->assertSame(1, substr_count((string) @file_get_contents($this->dir.'/calls'), "x\n"));
        $this->assertSame('SUCCEEDED', AiInsight::query()->findOrFail($insight->id)->status->value);
    }

    public function test_the_migration_rolls_back_and_reapplies_without_touching_earlier_phases(): void
    {
        $this->artisan('migrate:reset', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('ai_insights'));
        $this->assertSame([], DB::select("SELECT proname FROM pg_proc WHERE proname LIKE 'ai_insights_%'"));
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_indexes where indexname = 'challenge_submissions_owner_unique'"));
        foreach (['ai_assessments', 'growth_snapshots', 'roadmap_snapshots', 'challenge_submissions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('ai_insights'));
        $this->assertSame(1, (int) DB::scalar("select count(*) from pg_trigger where tgname = 'ai_insights_terminal_immutable'"));
    }
}
