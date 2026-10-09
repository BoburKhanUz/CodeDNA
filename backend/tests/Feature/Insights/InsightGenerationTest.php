<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Actions\Insights\RequestInsight;
use App\Actions\Roadmap\CompleteRoadmapStep;
use App\Enums\Insights\InsightKind;
use App\Jobs\GenerateInsight;
use App\Models\AiInsight;
use App\Models\ChallengeSubmission;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Ai\FakeModelClient;
use App\Services\Ai\ModelClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AssessmentFixtures;
use Tests\Support\BillingFixtures;
use Tests\Support\ChallengeFixtures;
use Tests\Support\InsightFixtures;
use Tests\Support\ScriptedModelClient;
use Tests\TestCase;

/**
 * GenerateInsight and the insight contract (Phase 29): the gateway call,
 * validation of every answer against the deterministic evidence, failure
 * handling, prompt-injection resistance, and the invariant that AI never
 * changes a deterministic result.
 */
final class InsightGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const DETERMINISTIC_TABLES = [
        'source_snapshots', 'analysis_runs', 'dna_snapshots', 'competency_snapshots', 'skill_gap_snapshots', 'skill_gap_results',
        'growth_snapshots', 'growth_observations', 'roadmap_snapshots', 'roadmap_steps', 'roadmap_step_completions',
        'challenge_instances', 'challenge_submissions',
    ];

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => true, 'codedna.ai.provider' => 'ollama', 'codedna.challenges.enabled' => true]);
        $this->owner = BillingFixtures::pro(User::factory()->create());
        $this->project = Project::factory()->for($this->owner)->create();
    }

    private function model(string|Closure ...$modes): ScriptedModelClient
    {
        $model = new ScriptedModelClient(...$modes);
        $this->app->instance(ModelClient::class, $model);

        return $model;
    }

    private function requested(InsightKind $kind, string $subjectId, ?Project $project = null): AiInsight
    {
        return app(RequestInsight::class)->handle($project ?? $this->project, $this->owner, $kind, $subjectId)->insight;
    }

    /** Runs $job, or a fresh delivery of the insight's job (a released job keeps its claim token). */
    private function runJob(AiInsight $insight, ?GenerateInsight $job = null): GenerateInsight
    {
        $job ??= (new GenerateInsight($insight->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    private function fresh(AiInsight $insight): AiInsight
    {
        return AiInsight::query()->findOrFail($insight->id);
    }

    private function used(): int
    {
        return (int) DB::table('billing_usage_counters')->where('quota_key', 'AI_ASSESSMENTS')->sum('used');
    }

    /**
     * The template answer for this request, changed by $edit.
     *
     * @param  Closure(array<string, mixed>, array<string, array<string, mixed>>): array<string, mixed>  $edit
     */
    private static function edited(Closure $edit): Closure
    {
        return function (array $items, AiRequest $request) use ($edit): array {
            $answer = json_decode((new FakeModelClient)->complete($request)->content, true);

            return $edit($answer, $items);
        };
    }

    /** @param  array<string, array<string, mixed>>  $items */
    private static function firstWhere(array $items, string $prefix, string $field, string $value): string
    {
        foreach ($items as $id => $item) {
            if (str_starts_with($id, $prefix) && ($item['facts'][$field] ?? null) === $value) {
                return $id;
            }
        }
        throw new RuntimeException("no {$prefix} with {$field}={$value}");
    }

    private function snapshotOfDeterministicTables(): string
    {
        $all = [];
        foreach (self::DETERMINISTIC_TABLES as $table) {
            $all[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', (string) json_encode($all));
    }

    public function test_a_valid_answer_is_stored_with_usage_and_duration_and_changes_no_deterministic_row(): void
    {
        $model = $this->model('valid');
        $growth = InsightFixtures::growth($this->project);
        $insight = $this->requested(InsightKind::GrowthInterpretation, $growth->id);
        $before = $this->snapshotOfDeterministicTables();

        $this->runJob($insight)->assertNotReleased();

        $stored = $this->fresh($insight);
        $this->assertSame('SUCCEEDED', $stored->status->value);
        $this->assertSame('insight/v1', $stored->output['schema_version']);
        $this->assertEqualsCanonicalizing(['served_model', 'duration_ms'], array_keys($stored->provider_metadata ?? []), 'the template reports no token counts: unknown, never guessed');
        $this->assertSame($before, $this->snapshotOfDeterministicTables(), 'AI never writes a deterministic row');
        // The model received the server-owned prompt, the schema narrowed to this input's ids, and nothing else.
        $request = $model->requests[0];
        $this->assertSame('growth_interpretation', $request->task);
        $this->assertStringContainsString('Only observations with status IMPROVED or REGRESSED are measured change.', $request->system);
        $this->assertSame(array_keys(ScriptedModelClient::evidence($request)), $request->schema['properties']['points']['items']['properties']['evidence_refs']['items']['enum']);
    }

    /**
     * An answer that tries to set scores, levels or a verdict is refused, and
     * even a refused or accepted answer leaves every deterministic row as it was.
     */
    public function test_ai_can_never_alter_deterministic_scores(): void
    {
        $growth = InsightFixtures::growth($this->project);
        $before = $this->snapshotOfDeterministicTables();
        $dna = DB::table('dna_snapshots')->orderBy('id')->pluck('overall_score')->all();

        foreach ([
            fn (array $a): array => $a + ['score' => 100],
            fn (array $a): array => array_replace_recursive($a, ['points' => [['title' => 'Overall', 'description' => 'The overall score is 100/100 now.', 'evidence_refs' => ['growth:summary']]]]),
            fn (array $a): array => array_replace_recursive($a, ['points' => [['title' => 'New level', 'description' => 'Set FUNCTION_DESIGN to STRONG with a score of 9.', 'evidence_refs' => ['growth:summary']]]]),
        ] as $edit) {
            $this->model(self::edited($edit));
            cache()->flush();
            $insight = $this->requested(InsightKind::GrowthInterpretation, $growth->id);
            $this->runJob($insight);
            $this->assertSame('FAILED', $this->fresh($insight)->status->value);
            $this->assertSame('INVALID_OUTPUT', $this->fresh($insight)->failure_code);
        }
        $this->assertSame($before, $this->snapshotOfDeterministicTables());
        $this->assertSame($dna, DB::table('dna_snapshots')->orderBy('id')->pluck('overall_score')->all());
    }

    /**
     * @return iterable<string, array{Closure, string}>
     */
    public static function invalidGrowthAnswers(): iterable
    {
        $point = fn (string $title, string $description, array $refs): Closure => fn (array $a, array $items): array => ['points' => [['title' => $title, 'description' => $description, 'evidence_refs' => $refs]]] + $a;
        yield 'unknown evidence id' => [$point('Change', 'A change.', ['obs:DNA:INVENTED']), 'evidence_ref_unknown'];
        yield 'a score' => [$point('Score', 'This is 100/100.', ['growth:summary']), 'numeric_score'];
        yield 'a number that is not in the evidence' => [$point('Count', 'There are 4242 functions.', ['growth:summary']), 'unsupported_number'];
        yield 'seniority' => [$point('Level', 'This reads like senior work.', ['growth:summary']), 'seniority'];
        yield 'a judgment of the person' => [$point('Person', 'The developer is weak at design.', ['growth:summary']), 'person_judgment'];
        yield 'a link' => [$point('Learn', 'Read https://example.com first.', ['growth:summary']), 'external_resource'];
        yield 'a course' => [$point('Learn', 'Take a course on refactoring.', ['growth:summary']), 'external_resource'];
        yield 'HTML' => [$point('Note', '<script>alert(1)</script>', ['growth:summary']), 'markup'];
        yield 'a Markdown image' => [$point('Note', 'See ![x](javascript:alert(1)).', ['growth:summary']), 'markup'];
        yield 'code' => [$point('Fix', 'Use def helper(x): to split it.', ['growth:summary']), 'code'];
        yield 'a prompt leak' => [$point('Rules', 'My system prompt says to be neutral.', ['growth:summary']), 'prompt_leak'];
        yield 'a control character' => [$point('Note', "A\u{0007}B", ['growth:summary']), 'control_characters'];
        yield 'an unmeasured topic' => [$point('Security', 'The security of the code improved.', ['growth:summary']), 'unsupported_claim'];
        yield 'improvement without an observation' => [$point('Overall', 'The code quality improved overall.', ['growth:summary']), 'direction_without_observation'];
        yield 'improvement of an unchanged metric' => [fn (array $a, array $items): array => ['points' => [['title' => 'Better', 'description' => 'This metric improved.', 'evidence_refs' => [self::firstWhere($items, 'obs:', 'status', 'UNCHANGED')]]]] + $a, 'direction_contradicts_evidence'];
        yield 'regression of an improved metric' => [fn (array $a, array $items): array => ['points' => [['title' => 'Worse', 'description' => 'This metric declined.', 'evidence_refs' => [self::firstWhere($items, 'obs:', 'status', 'IMPROVED')]]]] + $a, 'direction_contradicts_evidence'];
        yield 'a forbidden field' => [fn (array $a): array => ['points' => [['title' => 'X', 'description' => 'Y.', 'evidence_refs' => ['growth:summary'], 'level' => 'STRONG']]] + $a, 'forbidden_field'];
        yield 'a missing section' => [fn (array $a): array => array_diff_key($a, ['limitations' => true]), 'schema'];
        yield 'too many points' => [fn (array $a): array => ['points' => array_fill(0, 7, ['title' => 'X', 'description' => 'Y.', 'evidence_refs' => ['growth:summary']])] + $a, 'limits'];
    }

    #[DataProvider('invalidGrowthAnswers')]
    public function test_answers_that_break_the_evidence_rules_are_discarded(Closure $edit, string $rule): void
    {
        $this->model(self::edited($edit));
        $insight = $this->requested(InsightKind::GrowthInterpretation, InsightFixtures::growth($this->project)->id);

        $this->runJob($insight)->assertNotReleased();

        $stored = $this->fresh($insight);
        $this->assertSame(['FAILED', 'INVALID_OUTPUT', $rule], [$stored->status->value, $stored->failure_code, $stored->failure_detail]);
        $this->assertNull($stored->output);
        $this->assertSame(0, $this->used(), 'a failed insight gives its unit back');
    }

    public function test_negated_direction_words_and_matching_statuses_are_accepted(): void
    {
        $this->model(self::edited(fn (array $a, array $items): array => ['points' => [
            ['title' => 'Steady', 'description' => 'This metric did not improve or decline.', 'evidence_refs' => [self::firstWhere($items, 'obs:', 'status', 'UNCHANGED')]],
            ['title' => 'Closed gap', 'description' => 'The measured gap improved.', 'evidence_refs' => [self::firstWhere($items, 'obs:', 'status', 'IMPROVED')]],
        ]] + $a));
        $insight = $this->requested(InsightKind::GrowthInterpretation, InsightFixtures::growth($this->project)->id);

        $this->runJob($insight);

        $this->assertSame('SUCCEEDED', $this->fresh($insight)->status->value, (string) $this->fresh($insight)->failure_detail);
    }

    public function test_roadmap_guidance_may_only_recommend_available_steps(): void
    {
        $roadmap = InsightFixtures::roadmap($this->project, $this->owner);
        foreach ([
            ['LOCKED', 'step_not_available'],
            [null, 'next_step_without_step'],
        ] as [$state, $rule]) {
            $this->model(self::edited(fn (array $a, array $items): array => ['next_steps' => [[
                'title' => 'Next', 'description' => 'Do this next.',
                'evidence_refs' => [$state === null ? array_key_first(array_filter($items, fn ($i, $id) => str_starts_with($id, 'track:'), ARRAY_FILTER_USE_BOTH)) : self::firstWhere($items, 'step:', 'state', $state)],
            ]]] + $a));
            cache()->flush();
            $insight = $this->requested(InsightKind::RoadmapGuidance, $roadmap->id);
            $this->runJob($insight);
            $this->assertSame([$rule], [$this->fresh($insight)->failure_detail]);
        }
        // After completing it, the completed step cannot be "next" either.
        $step = (string) DB::table('roadmap_steps')->where('roadmap_snapshot_id', $roadmap->id)->where('prerequisites', '[]')->orderBy('position')->value('step_key');
        app(CompleteRoadmapStep::class)->handle($this->project, RoadmapSnapshot::query()->findOrFail($roadmap->id), $step, $this->owner);
        $this->model(self::edited(fn (array $a, array $items): array => ['next_steps' => [['title' => 'Next', 'description' => 'Do this next.', 'evidence_refs' => [self::firstWhere($items, 'step:', 'state', 'COMPLETED')]]]] + $a));
        $insight = $this->requested(InsightKind::RoadmapGuidance, $roadmap->id);
        $this->runJob($insight);
        $this->assertSame('step_not_available', $this->fresh($insight)->failure_detail);
    }

    public function test_challenge_feedback_follows_the_evaluator_and_never_sees_the_code_or_hidden_tests(): void
    {
        $submission = InsightFixtures::submission($this->project, $this->owner, 'wrong');
        $this->assertSame('FAILED', $submission->status->value);

        $model = $this->model(self::edited(fn (array $a): array => ['summary' => ['text' => 'All tests passed.', 'evidence_refs' => ['result:verdict']]] + $a));
        $insight = $this->requested(InsightKind::ChallengeFeedback, $submission->id);
        $this->runJob($insight);
        $this->assertSame('outcome_contradicts_evidence', $this->fresh($insight)->failure_detail, 'never "passed" for a FAILED verdict');

        $request = $model->requests[0];
        $sent = $request->system.$request->user;
        $this->assertStringNotContainsString('SOURCE_SECRET_MARKER', $sent, 'the submitted code is never sent');
        $evaluation = ChallengeSubmission::query()->findOrFail($submission->id)->evaluation;
        foreach ($evaluation['cases'] as $case) {
            if ($case['visibility'] === 'HIDDEN') {
                $this->assertEqualsCanonicalizing(['visibility', 'status', 'error_type'], array_keys(ScriptedModelClient::evidence($request)['case:'.$case['id']]['facts']));
            }
            foreach (['args', 'expected', 'observed'] as $field) {
                if (isset($case[$field])) {
                    $this->assertStringNotContainsString(json_encode($case[$field]), $sent, "case {$field} is never sent");
                }
            }
        }

        // A truthful answer passes.
        $this->model('valid');
        cache()->flush();
        $truthful = $this->requested(InsightKind::ChallengeFeedback, $submission->id);
        $this->runJob($truthful);
        $this->assertSame('SUCCEEDED', $this->fresh($truthful)->status->value, (string) $this->fresh($truthful)->failure_detail);
    }

    public function test_prompt_injection_in_anything_a_user_controls_never_reaches_the_model(): void
    {
        [$a, $b, $c, $d] = AssessmentFixtures::INJECTIONS;
        $owner = BillingFixtures::pro(User::factory()->create(['name' => $d]));
        $project = Project::factory()->for($owner)->create(['name' => $a, 'description' => $b]);
        $first = ChallengeFixtures::manyGaps($project);
        app(CalculateGrowthSnapshot::class)->handle($first->id);
        $this->travel(1)->hours();
        $second = AssessmentFixtures::skillGaps($project, AssessmentFixtures::injected(...));
        app(CalculateGrowthSnapshot::class)->handle($second->id);
        $growth = GrowthSnapshot::query()->where('skill_gap_snapshot_id', $second->id)->sole();
        $model = $this->model('valid');

        $insight = app(RequestInsight::class)->handle($project, $owner, InsightKind::GrowthInterpretation, $growth->id)->insight;
        $this->runJob($insight);

        $sent = $model->requests[0]->system.$model->requests[0]->user;
        foreach ([...AssessmentFixtures::INJECTIONS, 'src/', '.js'] as $planted) {
            $this->assertStringNotContainsString($planted, $sent);
        }
        $this->assertSame('SUCCEEDED', $this->fresh($insight)->status->value);

    }

    public function test_retryable_failures_are_retried_within_the_bound_and_permanent_ones_fail_once(): void
    {
        $growth = InsightFixtures::growth($this->project);
        $model = $this->model('timeout', 'valid');
        $insight = $this->requested(InsightKind::GrowthInterpretation, $growth->id);

        $job = $this->runJob($insight)->assertReleased(20);
        $this->assertSame(['RUNNING', 1], [$this->fresh($insight)->status->value, $this->fresh($insight)->attempts]);
        // Another delivery while the lease is held does nothing; the released job resumes it.
        $this->runJob($insight)->assertNotReleased();
        $this->assertCount(1, $model->requests);
        $this->runJob($insight, (new GenerateInsight($insight->id))->withFakeQueueInteractions())->assertNotReleased();
        $this->runJob($insight, $job->withFakeQueueInteractions())->assertNotReleased();
        $this->assertSame(['SUCCEEDED', 2], [$this->fresh($insight)->status->value, $this->fresh($insight)->attempts]);

        foreach (['auth' => 'PROVIDER_AUTH_FAILED', 'rejected' => 'PROVIDER_REJECTED', 'truncated' => 'OUTPUT_TOO_LARGE'] as $mode => $code) {
            $this->model($mode);
            cache()->flush();
            $p = Project::factory()->for($this->owner)->create();
            $failing = $this->requested(InsightKind::RoadmapGuidance, InsightFixtures::roadmap($p, $this->owner)->id, $p);
            $this->runJob($failing)->assertNotReleased();
            $this->assertSame(['FAILED', $code, 1], [$this->fresh($failing)->status->value, $this->fresh($failing)->failure_code, $this->fresh($failing)->attempts]);
        }

        // Three timeouts: the attempt bound holds.
        $this->model('timeout');
        $bounded = $this->requested(InsightKind::ChallengeFeedback, InsightFixtures::submission($p = Project::factory()->for($this->owner)->create(), $this->owner)->id, $p);
        $delivery = $this->runJob($bounded)->assertReleased();
        $this->runJob($bounded, $delivery->withFakeQueueInteractions())->assertReleased();
        $this->runJob($bounded, $delivery->withFakeQueueInteractions())->assertNotReleased();
        $this->assertSame(['FAILED', 'PROVIDER_TIMEOUT', 3], [$this->fresh($bounded)->status->value, $this->fresh($bounded)->failure_code, $this->fresh($bounded)->attempts]);
        $this->assertSame(1, $this->used(), 'only the succeeded insight keeps its unit');
    }

    public function test_changed_evidence_configuration_or_a_disabled_ai_fails_without_calling_the_model(): void
    {
        $roadmap = InsightFixtures::roadmap($this->project, $this->owner);
        $model = $this->model('valid');

        $changed = $this->requested(InsightKind::RoadmapGuidance, $roadmap->id);
        $step = (string) DB::table('roadmap_steps')->where('roadmap_snapshot_id', $roadmap->id)->where('prerequisites', '[]')->orderBy('position')->value('step_key');
        app(CompleteRoadmapStep::class)->handle($this->project, RoadmapSnapshot::query()->findOrFail($roadmap->id), $step, $this->owner);
        $this->runJob($changed);
        $this->assertSame(['EVIDENCE_CHANGED', 'input_fingerprint'], [$this->fresh($changed)->failure_code, $this->fresh($changed)->failure_detail]);

        $reconfigured = $this->requested(InsightKind::RoadmapGuidance, $roadmap->id);
        $this->app->instance(ModelClient::class, new FakeModelClient);
        $this->runJob($reconfigured);
        $this->assertSame('configuration_changed', $this->fresh($reconfigured)->failure_detail, 'never silently another model');

        $this->app->instance(ModelClient::class, $model);
        $disabled = $this->requested(InsightKind::GrowthInterpretation, InsightFixtures::growth($p = Project::factory()->for($this->owner)->create())->id, $p);
        config(['codedna.ai.enabled' => false]);
        $this->runJob($disabled);
        $this->assertSame('ai_disabled', $this->fresh($disabled)->failure_detail);

        config(['codedna.ai.enabled' => true]);
        $tooBig = $this->requested(InsightKind::ChallengeFeedback, InsightFixtures::submission($q = Project::factory()->for($this->owner)->create(), $this->owner)->id, $q);
        // The context window shrank after the request: the gateway refuses before sending.
        config(['codedna.ai.context_tokens' => 2048]);
        $this->runJob($tooBig);
        $this->assertSame(['INPUT_TOO_LARGE', 'context_budget'], [$this->fresh($tooBig)->failure_code, $this->fresh($tooBig)->failure_detail]);

        $this->assertSame([], $model->requests, 'none of them reached the model');
    }

    public function test_duplicate_deliveries_call_the_model_once(): void
    {
        $model = $this->model('valid');
        $insight = $this->requested(InsightKind::GrowthInterpretation, InsightFixtures::growth($this->project)->id);

        $this->runJob($insight);
        $this->runJob($insight);

        $this->assertCount(1, $model->requests);
        $this->assertSame('SUCCEEDED', $this->fresh($insight)->status->value);
    }

    /**
     * insight:fail-stale fails insights stuck in QUEUED or behind an expired
     * lease (refunded), and never one whose worker still holds its lease.
     */
    public function test_stuck_insights_are_failed_as_stale_and_refunded_but_live_ones_are_not(): void
    {
        $this->model('valid');
        $queued = $this->requested(InsightKind::GrowthInterpretation, InsightFixtures::growth($this->project)->id);
        $roadmapProject = Project::factory()->for($this->owner)->create();
        $expired = $this->requested(InsightKind::RoadmapGuidance, InsightFixtures::roadmap($roadmapProject, $this->owner)->id, $roadmapProject);
        $challengeProject = Project::factory()->for($this->owner)->create();
        $live = $this->requested(InsightKind::ChallengeFeedback, InsightFixtures::submission($challengeProject, $this->owner)->id, $challengeProject);
        $expired->forceFill(['status' => 'RUNNING', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute(), 'started_at' => now()])->save();
        $live->forceFill(['status' => 'RUNNING', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addDays(2), 'started_at' => now()])->save();
        $this->assertSame(3, $this->used());

        $this->travel((int) config('codedna.ai.queued_stale_after_seconds') + 1)->seconds();
        $this->artisan('insight:fail-stale')->expectsOutputToContain('Stale AI insights failed: 2')->assertSuccessful();

        foreach ([$queued, $expired] as $insight) {
            $stale = $this->fresh($insight);
            $this->assertSame('FAILED', $stale->status->value);
            $this->assertSame('INSIGHT_STALE', $stale->failure_code);
            $this->assertNull($stale->failure_detail);
            $this->assertNull($stale->claim_token);
            $this->assertNotNull($stale->completed_at);
        }
        $this->assertSame('RUNNING', $this->fresh($live)->status->value, 'a worker that still holds its lease keeps it');
        $this->assertSame(1, $this->used(), 'stale insights are refunded');
        $this->artisan('insight:fail-stale')->expectsOutputToContain('Stale AI insights failed: 0');
    }

    public function test_logs_carry_identifiers_and_codes_only(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });
        $this->model(self::edited(fn (array $a): array => ['summary' => ['text' => 'Secret answer text 4242.', 'evidence_refs' => ['growth:summary']]] + $a));
        $insight = $this->requested(InsightKind::GrowthInterpretation, InsightFixtures::growth($this->project)->id);
        $this->runJob($insight);

        $allowed = ['insight_id', 'project_id', 'kind', 'input_fingerprint', 'provider', 'model', 'attempt', 'status', 'error_code', 'reason', 'duration_ms',
            'requested_by', 'request_id', 'task', 'retryable', 'http_status', 'exception', 'retry_in_seconds'];
        $ours = array_filter($logged, fn (array $l): bool => str_starts_with($l[0], 'insight.') || str_starts_with($l[0], 'ai.'));
        $this->assertNotEmpty($ours);
        foreach ($ours as [$message, $context]) {
            $this->assertSame([], array_diff(array_keys($context), $allowed), $message);
            $this->assertStringNotContainsString('Secret answer text', json_encode($context));
            $this->assertStringNotContainsString('UNTRUSTED', json_encode($context));
        }
    }
}
