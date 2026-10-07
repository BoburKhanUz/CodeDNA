<?php

declare(strict_types=1);

namespace Tests\Feature\Challenge;

use App\Actions\Challenge\AssignChallenge;
use App\Actions\Challenge\SubmitChallengeSolution;
use App\Enums\Challenge\ChallengeStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\ChallengeDefinition;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Models\Project;
use App\Models\User;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ChallengeFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\TestCase;

/**
 * The challenge tables: immutable definitions, guarded instances and
 * submissions (models and database triggers), lineage, constraints and the
 * stale-evaluation command.
 */
final class ChallengeStorageTest extends TestCase
{
    use RefreshDatabase;

    private ChallengeInstance $challenge;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.challenges.enabled' => true]);
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator('wrong'));
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        ChallengeFixtures::manyGaps($project);
        $this->challenge = app(AssignChallenge::class)->handle($project, $owner, null, 'CODE_HYGIENE')->instance;
    }

    private function submitted(string $source = "def parse_config(text):\n    return {}\n"): ChallengeSubmission
    {
        $challenge = ChallengeInstance::query()->findOrFail($this->challenge->id);

        return app(SubmitChallengeSolution::class)->handle($challenge, User::query()->findOrFail($challenge->user_id), 'python', $source, null)->submission;
    }

    private function evaluated(): ChallengeSubmission
    {
        $submission = $this->submitted();
        app()->call([new EvaluateChallengeSubmission($submission->id), 'handle']);

        return ChallengeSubmission::query()->findOrFail($submission->id);
    }

    private function assertRefused(Closure $statement, string $constraint): void
    {
        try {
            DB::transaction($statement);
            $this->fail("Expected the database to refuse ({$constraint}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, string $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    public function test_a_published_definition_never_changes(): void
    {
        $definition = ChallengeDefinition::query()->firstOrFail();

        $this->assertRefused(fn () => DB::table('challenge_definitions')->where('id', $definition->id)->update(['title' => 'x']), 'is immutable');
        $this->expectException(DomainRuleViolation::class);
        $definition->forceFill(['title' => 'x'])->save();
    }

    public function test_definitions_are_stored_once_per_version(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        ChallengeFixtures::manyGaps($project);
        app(AssignChallenge::class)->handle($project, $owner, null, 'CODE_HYGIENE');

        $this->assertSame(1, ChallengeDefinition::query()->where('key', 'CODE_HYGIENE_001')->count());
        $copy = $this->row('challenge_definitions', ChallengeDefinition::query()->firstOrFail()->id);
        $copy['id'] = strtolower((string) Str::ulid());
        $this->assertRefused(fn () => DB::table('challenge_definitions')->insert($copy), 'challenge_definitions_key_version_unique');
    }

    public function test_a_closed_challenge_and_a_finished_attempt_are_immutable_in_the_database(): void
    {
        $submission = $this->evaluated();

        $this->assertRefused(fn () => DB::table('challenge_submissions')->where('id', $submission->id)->update(['status' => 'PASSED']), 'is immutable');
        $this->assertRefused(fn () => DB::table('challenge_submissions')->where('id', $submission->id)->update(['evaluation' => '{}']), 'is immutable');

        DB::table('challenge_instances')->where('id', $this->challenge->id)->update(['status' => 'PASSED', 'last_result' => 'PASSED', 'closed_at' => Carbon::now()]);
        $this->assertRefused(fn () => DB::table('challenge_instances')->where('id', $this->challenge->id)->update(['status' => 'ASSIGNED', 'closed_at' => null]), 'is immutable');
    }

    public function test_identity_provenance_and_source_never_change(): void
    {
        $this->evaluated();
        $submission = $this->submitted();

        $this->assertRefused(fn () => DB::table('challenge_instances')->where('id', $this->challenge->id)->update(['selection' => '{}']), 'identity and provenance');
        $this->assertRefused(fn () => DB::table('challenge_instances')->where('id', $this->challenge->id)->update(['max_attempts' => 20]), 'identity and provenance');
        $this->assertRefused(fn () => DB::table('challenge_instances')->where('id', $this->challenge->id)->update(['attempts_used' => 0]), 'never given back');
        $this->assertRefused(fn () => DB::table('challenge_submissions')->where('id', $submission->id)->update(['source' => 'x', 'source_sha256' => hash('sha256', 'x'), 'source_bytes' => 1]), 'immutable');

        foreach (['selection' => [], 'competency_key' => 'FUNCTION_DESIGN', 'definition_version' => '2.0.0'] as $column => $value) {
            try {
                ChallengeInstance::query()->findOrFail($this->challenge->id)->forceFill([$column => $value])->save();
                $this->fail("{$column} was changed");
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(DomainRuleViolation::class);
        ChallengeSubmission::query()->findOrFail($submission->id)->forceFill(['source' => 'other'])->save();
    }

    public function test_nothing_is_deleted_through_the_models(): void
    {
        $submission = $this->submitted();

        foreach ([$submission, ChallengeInstance::query()->findOrFail($this->challenge->id), ChallengeDefinition::query()->firstOrFail()] as $model) {
            try {
                $model->delete();
                $this->fail($model::class.' was deleted');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $this->expectException(DomainRuleViolation::class);
        ChallengeInstance::query()->findOrFail($this->challenge->id)->forceFill(['status' => ChallengeStatus::Passed, 'last_result' => 'PASSED', 'closed_at' => Carbon::now()])->save();
    }

    /**
     * The lineage cannot mix projects, owners or snapshots.
     */
    public function test_the_lineage_must_match_the_skill_gap_snapshot(): void
    {
        $mine = $this->row('challenge_instances', $this->challenge->id);
        $other = ChallengeFixtures::oneGap(Project::factory()->create());
        // A valid row for the other snapshot, then one field from this challenge's lineage.
        $copy = ['id' => strtolower((string) Str::ulid()), 'skill_gap_snapshot_id' => $other->id, 'project_id' => $other->project_id, 'user_id' => $other->user_id,
            'competency_snapshot_id' => $other->competency_snapshot_id, 'dna_snapshot_id' => $other->dna_snapshot_id,
            'analysis_run_id' => $other->analysis_run_id, 'source_snapshot_id' => $other->source_snapshot_id] + $mine;
        DB::transaction(fn () => DB::table('challenge_instances')->insert($copy));
        DB::table('challenge_instances')->where('id', $copy['id'])->delete();

        foreach (['project_id', 'user_id', 'dna_snapshot_id', 'competency_snapshot_id', 'source_snapshot_id'] as $column) {
            $this->assertRefused(fn () => DB::table('challenge_instances')->insert([$column => $mine[$column]] + $copy), 'challenge_instances_lineage_foreign');
        }
        $this->assertRefused(fn () => DB::table('challenge_instances')->insert(['definition_version' => '9.9.9'] + $copy), 'challenge_instances_definition_foreign');
        $this->assertRefused(fn () => DB::table('challenge_instances')->insert(['competency_key' => 'FUNCTION_DESIGN'] + $copy), 'challenge_instances_definition_matches_gap');
    }

    public function test_a_submission_must_belong_to_its_challenges_project_and_owner(): void
    {
        $submission = $this->evaluated();
        $copy = ['id' => strtolower((string) Str::ulid()), 'attempt_number' => 2, 'status' => 'QUEUED', 'evaluation' => null, 'evaluation_fingerprint' => null,
            'execution_status' => null, 'completed_at' => null, 'idempotency_key_hash' => null] + $this->row('challenge_submissions', $submission->id);

        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert(['user_id' => User::factory()->create()->id] + $copy), 'challenge_submissions_instance_foreign');
        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert(['project_id' => Project::factory()->create()->id] + $copy), 'challenge_submissions_instance_foreign');
    }

    public function test_the_challenge_and_attempt_constraints_hold(): void
    {
        $id = $this->challenge->id;
        $update = fn (array $values): Closure => fn () => DB::table('challenge_instances')->where('id', $id)->update($values);

        $this->assertRefused($update(['status' => 'CANCELLED']), 'challenge_instances_status_valid');
        $this->assertRefused($update(['status' => 'FAILED', 'closed_at' => Carbon::now()]), 'challenge_instances_failed_iff_exhausted');
        $this->assertRefused($update(['status' => 'PASSED', 'closed_at' => Carbon::now()]), 'challenge_instances_passed_result');
        $this->assertRefused($update(['closed_at' => Carbon::now()]), 'challenge_instances_closed_iff_terminal');
        $this->assertRefused($update(['attempts_used' => 6]), 'challenge_instances_attempts_range');

        $submission = $this->submitted();
        $sub = fn (array $values): Closure => fn () => DB::table('challenge_submissions')->where('id', $submission->id)->update($values);
        $this->assertRefused($sub(['status' => 'PASSED', 'completed_at' => Carbon::now()]), 'challenge_submissions_evaluation_iff_graded');
        $this->assertRefused($sub(['status' => 'ERROR', 'completed_at' => Carbon::now()]), 'challenge_submissions_failure_iff_error');
        $this->assertRefused($sub(['status' => 'RUNNING']), 'challenge_submissions_lease_iff_running');
        $this->assertRefused($sub(['failure_detail' => 'Free text']), 'challenge_submissions_failure_format');
    }

    public function test_source_is_bounded_and_matches_its_hash(): void
    {
        $submission = $this->submitted();
        $copy = ['id' => strtolower((string) Str::ulid()), 'attempt_number' => 2] + $this->row('challenge_submissions', $submission->id);
        DB::table('challenge_submissions')->where('id', $submission->id)->update(['status' => 'ERROR', 'failure_code' => 'EVALUATION_FAILED', 'completed_at' => Carbon::now()]);
        DB::table('challenge_instances')->where('id', $this->challenge->id)->update(['status' => 'ASSIGNED']);

        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert(['source_sha256' => str_repeat('a', 64)] + $copy), 'challenge_submissions_source_bounded');
        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert(['source_bytes' => 3] + $copy), 'challenge_submissions_source_bounded');
        $big = str_repeat('x', 65537);
        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert(['source' => $big, 'source_bytes' => 65537, 'source_sha256' => hash('sha256', $big)] + $copy), 'challenge_submissions_source_bounded');
        // The trigger refuses a language other than the challenge's before the CHECK does.
        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert(['language' => 'bash'] + $copy), 'language differs from the challenge');
    }

    /**
     * Attempt limit, one pending attempt and challenge state are enforced by
     * the database too, not only by the application.
     */
    public function test_the_database_refuses_attempts_the_application_would_refuse(): void
    {
        $submission = $this->submitted();
        $copy = ['id' => strtolower((string) Str::ulid()), 'attempt_number' => 2] + $this->row('challenge_submissions', $submission->id);

        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert($copy), 'does not accept submissions (EVALUATING)');
        DB::table('challenge_instances')->where('id', $this->challenge->id)->update(['status' => 'ASSIGNED']);
        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert($copy), 'challenge_submissions_one_pending_unique');

        DB::table('challenge_submissions')->where('id', $submission->id)->update(['status' => 'FAILED', 'evaluation' => '{}', 'evaluation_fingerprint' => str_repeat('a', 64),
            'execution_status' => 'COMPLETED', 'completed_at' => Carbon::now()]);
        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert(['attempt_number' => 1] + $copy), 'challenge_submissions_attempt_unique');

        DB::statement('ALTER TABLE challenge_instances DISABLE TRIGGER challenge_instances_guarded');
        DB::table('challenge_instances')->where('id', $this->challenge->id)->update(['max_attempts' => 1, 'attempts_used' => 1, 'last_result' => 'ERROR']);
        DB::statement('ALTER TABLE challenge_instances ENABLE TRIGGER challenge_instances_guarded');
        $this->assertRefused(fn () => DB::table('challenge_submissions')->insert($copy), 'no attempts left');
    }

    public function test_a_second_active_challenge_for_the_same_gap_is_refused(): void
    {
        $active = app(AssignChallenge::class)->handle(Project::query()->findOrFail($this->challenge->project_id), User::query()->findOrFail($this->challenge->user_id), null, 'COMPLEXITY_MANAGEMENT')->instance;
        // Another stored definition of the same competency, so only the "one active challenge per gap" index applies.
        $other = ['id' => strtolower((string) Str::ulid()), 'key' => 'COMPLEXITY_MANAGEMENT_999', 'title' => 'Other'] + $this->row('challenge_definitions', $active->challenge_definition_id);
        DB::table('challenge_definitions')->insert($other);
        $copy = ['id' => strtolower((string) Str::ulid()), 'challenge_definition_id' => $other['id'], 'definition_key' => $other['key']] + $this->row('challenge_instances', $active->id);

        $this->assertRefused(fn () => DB::table('challenge_instances')->insert($copy), 'challenge_instances_active_per_gap_unique');
        DB::table('challenge_instances')->insert(['status' => 'FAILED', 'attempts_used' => $copy['max_attempts'], 'last_result' => 'FAILED', 'closed_at' => Carbon::now()] + $copy);
        $this->assertSame(2, ChallengeInstance::query()->where('competency_key', 'COMPLEXITY_MANAGEMENT')->count(), 'closed challenges do not count');
    }

    public function test_the_tables_have_no_column_for_secrets_or_execution_settings(): void
    {
        $columns = DB::select("select table_name, column_name, data_type from information_schema.columns where table_schema = 'public' and table_name like 'challenge_%'");

        foreach ($columns as $column) {
            $this->assertNotSame('bytea', $column->data_type);
            $this->assertDoesNotMatchRegularExpression('/(command|image|script|dependenc|api_key|access_token|secret|storage_key|url|path)/', $column->column_name, "{$column->table_name}.{$column->column_name}");
        }
    }

    public function test_stale_evaluations_end_as_errors_and_reopen_the_challenge(): void
    {
        $queued = $this->submitted();
        $this->artisan('challenge:fail-stale')->expectsOutput('Stale challenge evaluations ended: 0')->assertSuccessful();
        DB::table('challenge_submissions')->where('id', $queued->id)->update(['updated_at' => Carbon::now()->subMinutes(50)]);
        $this->artisan('challenge:fail-stale')->expectsOutput('Stale challenge evaluations ended: 0')->assertSuccessful();
        DB::table('challenge_submissions')->where('id', $queued->id)->update(['updated_at' => Carbon::now()->subHours(2)]);

        $this->artisan('challenge:fail-stale')->expectsOutput('Stale challenge evaluations ended: 1')->assertSuccessful();

        $this->assertSame(['ERROR', 'EVALUATION_STALE'], [$this->row('challenge_submissions', $queued->id)['status'], $this->row('challenge_submissions', $queued->id)['failure_code']]);
        $this->assertSame(['ASSIGNED', 0, 'ERROR'], [$this->row('challenge_instances', $this->challenge->id)['status'], $this->row('challenge_instances', $this->challenge->id)['attempts_used'], $this->row('challenge_instances', $this->challenge->id)['last_result']]);

        $running = $this->submitted();
        DB::table('challenge_submissions')->where('id', $running->id)->update(['status' => 'RUNNING', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => Carbon::now()->addMinute(), 'updated_at' => Carbon::now()->subHour()]);
        $this->artisan('challenge:fail-stale')->expectsOutput('Stale challenge evaluations ended: 0')->assertSuccessful();
        DB::table('challenge_submissions')->where('id', $running->id)->update(['lease_expires_at' => Carbon::now()->subMinute(), 'updated_at' => Carbon::now()->subHour()]);
        $this->artisan('challenge:fail-stale')->expectsOutput('Stale challenge evaluations ended: 1')->assertSuccessful();
    }

    public function test_the_stale_sweeper_spares_a_lease_renewed_after_it_was_selected(): void
    {
        $submission = $this->submitted();
        DB::table('challenge_submissions')->where('id', $submission->id)->update(['status' => 'RUNNING', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => Carbon::now()->subMinute(), 'updated_at' => Carbon::now()->subHour()]);
        $this->afterCandidateQuery('challenge_submissions', fn () => DB::table('challenge_submissions')->where('id', $submission->id)->update(['lease_expires_at' => Carbon::now()->addMinute(), 'updated_at' => Carbon::now()]));

        $this->artisan('challenge:fail-stale')->expectsOutput('Stale challenge evaluations ended: 0')->assertSuccessful();

        $this->assertSame(['RUNNING', null], [$this->row('challenge_submissions', $submission->id)['status'], $this->row('challenge_submissions', $submission->id)['failure_code']]);
        $this->assertSame('EVALUATING', $this->row('challenge_instances', $this->challenge->id)['status']);
    }

    /**
     * Runs $touch once, right after the sweeper has read its candidates and
     * before it locks them: a worker renewing the row in between.
     */
    private function afterCandidateQuery(string $table, Closure $touch): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($table, &$done, $touch): void {
            if (! $done && str_starts_with($query->sql, "select \"id\" from \"{$table}\"")) {
                $done = true;
                $touch();
            }
        });
    }
}
