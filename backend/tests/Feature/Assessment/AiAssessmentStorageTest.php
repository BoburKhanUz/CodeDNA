<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Actions\Assessment\RequestAssessment;
use App\Enums\Assessment\AssessmentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\GenerateAssessment;
use App\Models\AiAssessment;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Assessment\Provider\AiProvider;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssessmentFixtures;
use Tests\Support\BillingFixtures;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * The ai_assessments table and model: immutable terminal records (model
 * and database trigger), lifecycle constraints, the active-identity unique
 * index, lineage and the stale-assessment command.
 */
final class AiAssessmentStorageTest extends TestCase
{
    use RefreshDatabase;

    private SkillGapSnapshot $gaps;

    private ScriptedAiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => true]);
        $this->provider = new ScriptedAiProvider('valid');
        $this->app->instance(AiProvider::class, $this->provider);
        $this->gaps = AssessmentFixtures::skillGaps(Project::factory()->for(BillingFixtures::pro(User::factory()->create()))->create());
    }

    private function queued(): AiAssessment
    {
        $project = Project::query()->findOrFail($this->gaps->project_id);

        return app(RequestAssessment::class)->handle($project, $project->user()->firstOrFail(), null)->assessment;
    }

    private function succeeded(): AiAssessment
    {
        $assessment = $this->queued();
        app()->call([new GenerateAssessment($assessment->id), 'handle']);

        return AiAssessment::query()->findOrFail($assessment->id);
    }

    /**
     * Runs a statement that the database must refuse, inside a savepoint.
     */
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
    private function row(AiAssessment $assessment): array
    {
        return (array) DB::table('ai_assessments')->where('id', $assessment->id)->first();
    }

    public function test_a_terminal_assessment_cannot_be_changed_through_the_model(): void
    {
        $assessment = $this->succeeded();

        $this->expectException(DomainRuleViolation::class);
        $assessment->forceFill(['output' => ['schema_version' => 'assessment/v1', 'summary' => ['text' => 'edited']]])->save();
    }

    public function test_a_terminal_assessment_cannot_be_changed_in_the_database(): void
    {
        $assessment = $this->succeeded();

        $this->assertRefused(fn () => DB::table('ai_assessments')->where('id', $assessment->id)->update(['model' => 'other']), 'is immutable');
        $this->assertRefused(fn () => DB::table('ai_assessments')->where('id', $assessment->id)->update(['status' => 'FAILED', 'failure_code' => 'ASSESSMENT_FAILED']), 'is immutable');
        $this->assertSame('SUCCEEDED', $this->row($assessment)['status']);
    }

    public function test_assessments_are_never_deleted_through_the_model(): void
    {
        $assessment = $this->queued();

        $this->expectException(DomainRuleViolation::class);
        $assessment->delete();
    }

    public function test_identity_and_input_never_change(): void
    {
        $assessment = $this->queued();

        foreach (['model' => 'other', 'input_fingerprint' => str_repeat('a', 64), 'project_id' => $this->gaps->id, 'input' => ['lineage' => [], 'payload' => []]] as $column => $value) {
            try {
                AiAssessment::query()->findOrFail($assessment->id)->forceFill([$column => $value])->save();
                $this->fail("{$column} was changed");
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $assessment = $this->queued();

        $this->expectException(DomainRuleViolation::class);
        $assessment->forceFill(['status' => AssessmentStatus::Succeeded, 'output' => ['a' => 1], 'output_fingerprint' => str_repeat('a', 64), 'completed_at' => Carbon::now()])->save();
    }

    public function test_the_lifecycle_constraints_hold_in_the_database(): void
    {
        $id = $this->queued()->id;
        $update = fn (array $values): Closure => fn () => DB::table('ai_assessments')->where('id', $id)->update($values);

        $this->assertRefused($update(['status' => 'SUCCEEDED', 'completed_at' => Carbon::now()]), 'ai_assessments_output_iff_succeeded');
        $this->assertRefused($update(['status' => 'FAILED', 'completed_at' => Carbon::now()]), 'ai_assessments_failure_iff_failed');
        $this->assertRefused($update(['status' => 'FAILED', 'failure_code' => 'ASSESSMENT_FAILED']), 'ai_assessments_completed_iff_terminal');
        $this->assertRefused($update(['status' => 'RUNNING']), 'ai_assessments_lease_iff_running');
        $this->assertRefused($update(['status' => 'CANCELLED']), 'ai_assessments_status_valid');
        $this->assertRefused($update(['output_fingerprint' => 'not-a-hash']), 'ai_assessments_fingerprints_sha256');
        $this->assertRefused($update(['model' => "model\nnewline"]), 'ai_assessments_provider_format');
        $this->assertRefused($update(['failure_detail' => 'Free text from a provider']), 'ai_assessments_failure_format');
        $this->assertRefused($update(['input' => '[]']), 'ai_assessments_input_object');
        $this->assertRefused($update(['attempts' => 11]), 'ai_assessments_attempts_range');
    }

    /**
     * One active assessment per identity; FAILED ones never block.
     */
    public function test_the_active_identity_is_unique_in_the_database(): void
    {
        $assessment = $this->queued();
        $copy = $this->row($assessment);
        $copy['id'] = strtolower((string) Str::ulid());

        $this->assertRefused(fn () => DB::table('ai_assessments')->insert($copy), 'ai_assessments_identity_active_unique');

        DB::table('ai_assessments')->insert(['status' => 'FAILED', 'failure_code' => 'ASSESSMENT_FAILED', 'completed_at' => Carbon::now()] + $copy);
        $copy['id'] = strtolower((string) Str::ulid());
        DB::table('ai_assessments')->insert(['status' => 'FAILED', 'failure_code' => 'ASSESSMENT_FAILED', 'completed_at' => Carbon::now()] + $copy);
        $this->assertSame(3, AiAssessment::query()->count());
    }

    public function test_the_lineage_must_match_the_skill_gap_snapshot(): void
    {
        $assessment = $this->queued();
        $other = AssessmentFixtures::skillGaps(Project::factory()->create());
        $copy = ['id' => strtolower((string) Str::ulid()), 'model' => 'other-model'] + $this->row($assessment);

        $this->assertRefused(fn () => DB::table('ai_assessments')->insert(['dna_snapshot_id' => $other->dna_snapshot_id] + $copy), 'ai_assessments_lineage_foreign');
        $this->assertRefused(fn () => DB::table('ai_assessments')->insert(['user_id' => $other->user_id] + $copy), 'ai_assessments_lineage_foreign');
        $this->assertRefused(fn () => DB::table('ai_assessments')->insert(['project_id' => $other->project_id] + $copy), 'ai_assessments_lineage_foreign');
    }

    public function test_snapshots_with_assessments_cannot_be_deleted(): void
    {
        $this->queued();

        $this->assertRefused(fn () => DB::table('skill_gap_results')->where('skill_gap_snapshot_id', $this->gaps->id)->delete() && DB::table('skill_gap_snapshots')->where('id', $this->gaps->id)->delete(), 'ai_assessments_lineage_foreign');
    }

    /**
     * Nothing in the table can hold source, a prompt, a key or a raw response.
     */
    public function test_the_table_has_no_column_for_source_prompts_keys_or_raw_responses(): void
    {
        $columns = DB::select("select column_name, data_type from information_schema.columns where table_schema = 'public' and table_name = 'ai_assessments'");

        $this->assertCount(34, $columns);
        foreach ($columns as $column) {
            $this->assertNotSame('bytea', $column->data_type, $column->column_name);
            $this->assertDoesNotMatchRegularExpression(
                '/(source_code|content|prompt_text|system_prompt|api_key|access_token|bearer|secret|authorization|header|raw|response_body|archive)/',
                $column->column_name,
            );
        }
    }

    public function test_stale_assessments_are_failed(): void
    {
        // Each model is another identity, so each request creates a row.
        $queue = function (string $model): AiAssessment {
            $this->provider->modelName = $model;

            return $this->queued();
        };
        $lease = fn (Carbon $expires): array => ['status' => 'RUNNING', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => $expires];
        $queued = $queue('queued-model');
        $running = $queue('running-model');
        $live = $queue('live-model');
        $fresh = $queue('fresh-model');
        DB::table('ai_assessments')->where('id', $queued->id)->update(['updated_at' => Carbon::now()->subHours(2)]);
        DB::table('ai_assessments')->where('id', $running->id)->update($lease(Carbon::now()->subMinute()) + ['updated_at' => Carbon::now()->subHour()]);
        DB::table('ai_assessments')->where('id', $live->id)->update($lease(Carbon::now()->addMinute()) + ['updated_at' => Carbon::now()->subHour()]);

        $this->artisan('assessment:fail-stale')->expectsOutput('Stale AI assessments failed: 2')->assertSuccessful();

        $status = fn (AiAssessment $a): array => [$this->row($a)['status'], $this->row($a)['failure_code']];
        $this->assertSame(['FAILED', 'ASSESSMENT_STALE'], $status($queued));
        $this->assertSame(['FAILED', 'ASSESSMENT_STALE'], $status($running));
        $this->assertSame(['RUNNING', null], $status($live), 'a live lease is never failed');
        $this->assertSame(['QUEUED', null], $status($fresh));
        $this->assertNull($this->row($running)['claim_token']);
    }

    public function test_the_stale_sweeper_spares_a_lease_renewed_after_it_was_selected(): void
    {
        $assessment = $this->queued();
        DB::table('ai_assessments')->where('id', $assessment->id)->update(['status' => 'RUNNING', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => Carbon::now()->subMinute(), 'updated_at' => Carbon::now()->subHour()]);
        $this->afterCandidateQuery('ai_assessments', fn () => DB::table('ai_assessments')->where('id', $assessment->id)->update(['lease_expires_at' => Carbon::now()->addMinute(), 'updated_at' => Carbon::now()]));

        $this->artisan('assessment:fail-stale')->expectsOutput('Stale AI assessments failed: 0')->assertSuccessful();

        $this->assertSame(['RUNNING', null], [$this->row($assessment)['status'], $this->row($assessment)['failure_code']]);
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
