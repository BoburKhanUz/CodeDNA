<?php

declare(strict_types=1);

namespace Tests\Feature\Challenge;

use App\Actions\Challenge\AssignChallenge;
use App\Actions\Challenge\SubmitChallengeSolution;
use App\Enums\Challenge\SubmissionFailure;
use App\Exceptions\ApiException;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use App\Services\Challenge\Evaluator\EvaluationRequest;
use App\Services\Challenge\Evaluator\EvaluatorException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ChallengeFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\TestCase;
use Throwable;

/**
 * Committed-data tests (no RefreshDatabase transaction): concurrent
 * assignment, submission, evaluation and stale-lease takeover in forked
 * processes, and the Phase 16 migration's rollback and re-application.
 * Everything created is deleted.
 */
final class ChallengeMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_13_000001_create_challenge_tables.php';

    /** @var list<SkillGapSnapshot> */
    private array $gaps = [];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->dir = sys_get_temp_dir().'/codedna-challenge-'.Str::random(8);
        mkdir($this->dir);
        config(['codedna.challenges.enabled' => true]);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        foreach ($this->gaps as $gaps) {
            $projectId = $gaps->project_id;
            DB::table('challenge_submissions')->where('project_id', $projectId)->delete();
            DB::table('challenge_instances')->where('project_id', $projectId)->delete();
            DB::table('skill_gap_results')->where('project_id', $projectId)->delete();
            DB::table('skill_gap_snapshots')->where('project_id', $projectId)->delete();
            DB::table('competency_snapshots')->where('project_id', $projectId)->delete();
            DB::table('dna_snapshots')->where('project_id', $projectId)->delete();
            DB::table('analysis_results')->where('analysis_run_id', $gaps->analysis_run_id)->delete();
            DB::table('analysis_runs')->where('project_id', $projectId)->delete();
            DB::table('source_snapshots')->where('project_id', $projectId)->delete();
            DB::table('projects')->where('id', $projectId)->delete();
            DB::table('users')->where('id', $gaps->user_id)->delete();
        }
        // Definitions are shared catalog copies; remove the unreferenced ones this test created.
        DB::table('challenge_definitions')->whereNotIn('id', DB::table('challenge_instances')->select('challenge_definition_id'))->delete();
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function gaps(): SkillGapSnapshot
    {
        return $this->gaps[] = ChallengeFixtures::manyGaps(Project::factory()->for(User::factory())->create());
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

    /**
     * An evaluator that records each call in a file shared across forks.
     */
    private function countingEvaluator(string $mode = 'pass', int $sleepMs = 300): ChallengeEvaluator
    {
        return new class($this->dir, $mode, $sleepMs) implements ChallengeEvaluator
        {
            public function __construct(private readonly string $dir, private readonly string $mode, private readonly int $sleepMs) {}

            public function name(): string
            {
                return 'fake';
            }

            public function available(): bool
            {
                return true;
            }

            public function evaluate(EvaluationRequest $request): array
            {
                file_put_contents($this->dir.'/calls', "call\n", FILE_APPEND | LOCK_EX);
                usleep($this->sleepMs * 1000);
                if ($this->mode === 'unavailable') {
                    throw new EvaluatorException(SubmissionFailure::EvaluatorUnavailable, true, 'not_claimed');
                }

                return (new FakeChallengeEvaluator($this->mode))->evaluate($request);
            }
        };
    }

    private function calls(): int
    {
        return substr_count((string) @file_get_contents($this->dir.'/calls'), 'call');
    }

    public function test_concurrent_assignment_creates_one_challenge(): void
    {
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator);
        $projectId = $this->gaps()->project_id;

        $results = $this->concurrently(8, function () use ($projectId): string {
            $project = Project::query()->findOrFail($projectId);
            $assigned = app(AssignChallenge::class)->handle($project, $project->user()->firstOrFail(), null, null);

            return $assigned->instance->id.'|'.($assigned->created ? 'created' : 'existing');
        });

        foreach ($results as $result) {
            $this->assertMatchesRegularExpression('/^[0-9a-z]{26}\|(created|existing)$/', $result, $result);
        }
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode('|', $r)[0], $results)));
        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_ends_with($r, '|created')));
        $this->assertSame(1, ChallengeInstance::query()->where('project_id', $projectId)->count());
    }

    /**
     * Two projects publishing the same catalog definition for the first time.
     */
    public function test_concurrent_first_use_of_a_definition_stores_it_once(): void
    {
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator);
        DB::table('challenge_definitions')->whereNotIn('id', DB::table('challenge_instances')->select('challenge_definition_id'))->delete();
        $projects = array_map(fn () => $this->gaps()->project_id, range(1, 4));

        $results = $this->concurrently(4, function (int $i) use ($projects): string {
            $project = Project::query()->findOrFail($projects[$i]);

            return app(AssignChallenge::class)->handle($project, $project->user()->firstOrFail(), null, 'CODE_HYGIENE')->instance->definition_key;
        });

        $this->assertSame(array_fill(0, 4, 'CODE_HYGIENE_001'), $results);
        $this->assertSame(1, DB::table('challenge_definitions')->where('key', 'CODE_HYGIENE_001')->count());
    }

    public function test_concurrent_submissions_record_one_pending_attempt(): void
    {
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator);
        $gaps = $this->gaps();
        $project = Project::query()->findOrFail($gaps->project_id);
        $challengeId = app(AssignChallenge::class)->handle($project, $project->user()->firstOrFail(), null, 'CODE_HYGIENE')->instance->id;

        $results = $this->concurrently(6, function (int $i) use ($challengeId): string {
            $challenge = ChallengeInstance::query()->findOrFail($challengeId);
            try {
                $submitted = app(SubmitChallengeSolution::class)->handle($challenge, User::query()->findOrFail($challenge->user_id), 'python', "def parse_config(text):\n    return {}  # {$i}\n", null);

                return 'created:'.$submitted->submission->id;
            } catch (ApiException $e) {
                return 'refused:'.$e->errorCode->value;
            }
        });

        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_starts_with($r, 'created:')), implode(', ', $results));
        $this->assertSame(array_fill(0, 5, 'refused:CHALLENGE_EVALUATION_PENDING'), array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'refused:'))));
        $this->assertSame(1, ChallengeSubmission::query()->where('challenge_instance_id', $challengeId)->count());
    }

    public function test_concurrent_jobs_evaluate_a_submission_once(): void
    {
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator);
        $gaps = $this->gaps();
        $project = Project::query()->findOrFail($gaps->project_id);
        $challenge = app(AssignChallenge::class)->handle($project, $project->user()->firstOrFail(), null, 'CODE_HYGIENE')->instance;
        $submissionId = app(SubmitChallengeSolution::class)->handle($challenge, $project->user()->firstOrFail(), 'python', "def parse_config(text):\n    return {}\n", null)->submission->id;
        $this->app->instance(ChallengeEvaluator::class, $this->countingEvaluator());

        $results = $this->concurrently(6, function () use ($submissionId): string {
            app()->call([new EvaluateChallengeSubmission($submissionId), 'handle']);

            return 'done';
        });

        $this->assertSame(array_fill(0, 6, 'done'), $results);
        $this->assertSame(1, $this->calls());
        $submission = ChallengeSubmission::query()->findOrFail($submissionId);
        $this->assertSame(['PASSED', 1], [$submission->status->value, $submission->job_attempts]);
        $this->assertSame(['PASSED', 1], [ChallengeInstance::query()->findOrFail($challenge->id)->status->value, ChallengeInstance::query()->findOrFail($challenge->id)->attempts_used]);
    }

    /**
     * Lease takeover across processes: job A holds an expired lease and is
     * still waiting for the evaluator when job B takes the submission over
     * and finishes it. A's late result must not be written.
     */
    public function test_a_stale_job_cannot_overwrite_the_result_of_the_job_that_took_over(): void
    {
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator);
        $gaps = $this->gaps();
        $project = Project::query()->findOrFail($gaps->project_id);
        $challenge = app(AssignChallenge::class)->handle($project, $project->user()->firstOrFail(), null, 'CODE_HYGIENE')->instance;
        $submissionId = app(SubmitChallengeSolution::class)->handle($challenge, $project->user()->firstOrFail(), 'python', "def parse_config(text):\n    return {}\n", null)->submission->id;

        $results = $this->concurrently(2, function (int $i) use ($submissionId): string {
            if ($i === 0) {
                // Job A: slow evaluator that "passes"; its lease is expired while it waits.
                $this->app->instance(ChallengeEvaluator::class, $this->countingEvaluator('pass', 1500));
                app()->call([new EvaluateChallengeSubmission($submissionId), 'handle']);

                return 'A';
            }
            // Job B: waits until A holds the lease, expires it (a stalled worker), takes over, and fails the attempt.
            for ($n = 0; $n < 100 && DB::table('challenge_submissions')->where('id', $submissionId)->value('status') !== 'RUNNING'; $n++) {
                usleep(20000);
            }
            DB::table('challenge_submissions')->where('id', $submissionId)->update(['lease_expires_at' => Carbon::now()->subSecond()]);
            $this->app->instance(ChallengeEvaluator::class, $this->countingEvaluator('wrong', 0));
            app()->call([new EvaluateChallengeSubmission($submissionId), 'handle']);

            return 'B';
        });

        $this->assertSame(['A', 'B'], $results);
        $this->assertSame(2, $this->calls());
        $submission = ChallengeSubmission::query()->findOrFail($submissionId);
        $this->assertSame(['FAILED', 'FAILED'], [$submission->status->value, $submission->evaluation['verdict']], "B's verdict stands; A's late PASS is discarded");
        $this->assertSame(['ASSIGNED', 1, 'FAILED'], [
            ChallengeInstance::query()->findOrFail($challenge->id)->status->value,
            ChallengeInstance::query()->findOrFail($challenge->id)->attempts_used,
            ChallengeInstance::query()->findOrFail($challenge->id)->last_result,
        ]);
    }

    public function test_constraints_rollback_and_reapplication(): void
    {
        $constraints = fn (string $table): array => array_map(fn (object $r): string => $r->conname, DB::select(
            'select conname from pg_constraint where conrelid = ?::regclass order by conname', [$table],
        ));
        foreach (['challenge_instances_lineage_foreign', 'challenge_instances_definition_foreign', 'challenge_instances_failed_iff_exhausted',
            'challenge_instances_definition_matches_gap'] as $name) {
            $this->assertContains($name, $constraints('challenge_instances'));
        }
        foreach (['challenge_submissions_instance_foreign', 'challenge_submissions_source_bounded', 'challenge_submissions_evaluation_iff_graded'] as $name) {
            $this->assertContains($name, $constraints('challenge_submissions'));
        }
        foreach (['challenge_definitions', 'challenge_instances', 'challenge_submissions'] as $table) {
            $this->assertSame(0, (int) DB::scalar("select count(*) from pg_constraint where conrelid = ?::regclass and contype = 'f' and confdeltype <> 'r'", [$table]), "{$table}: no cascades");
        }
        foreach (['challenge_definitions_immutable', 'challenge_instances_guarded', 'challenge_submissions_guarded'] as $trigger) {
            $this->assertSame(1, (int) DB::scalar('select count(*) from pg_trigger where tgname = ?', [$trigger]));
        }

        $gaps = $this->gaps();
        $this->artisan('migrate:reset', ['--path' => self::MIGRATION])->assertSuccessful();
        foreach (['challenge_definitions', 'challenge_instances', 'challenge_submissions'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_proc where proname like 'challenge_%'"));
        $this->assertSame(1, DB::table('skill_gap_snapshots')->where('id', $gaps->id)->count(), 'skill gap history is untouched');
        $this->assertTrue(Schema::hasTable('ai_assessments'), 'Phase 15 is untouched');

        $this->artisan('migrate')->assertSuccessful();
        $project = Project::query()->findOrFail($gaps->project_id);
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator);
        $this->assertTrue(app(AssignChallenge::class)->handle($project, $project->user()->firstOrFail(), null, null)->created, 'existing analyses get challenges after the upgrade');
    }
}
