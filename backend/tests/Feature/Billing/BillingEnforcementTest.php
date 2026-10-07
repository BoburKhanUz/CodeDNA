<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\QuotaKey;
use App\Enums\Billing\UsageOutcome;
use App\Enums\ProjectStatus;
use App\Jobs\ImportGitHubSource;
use App\Models\AiAssessment;
use App\Models\AnalysisRun;
use App\Models\BillingUsageEvent;
use App\Models\ChallengeSubmission;
use App\Models\GitHubImport;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Billing\BillingContextResolver;
use App\Services\Billing\UsageService;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\AssessmentFixtures;
use Tests\Support\BillingFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\Support\FakeGitHub;
use Tests\Support\GitHubFixtures;
use Tests\Support\ScriptedAiProvider;
use Tests\Support\SendsBillingWebhooks;
use Tests\Support\ZipBuilder;
use Tests\TestCase;

/**
 * Phase 23: billing is enforced on the real operations, through their
 * real endpoints (docs/billing/entitlements-and-quotas.md).
 */
final class BillingEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use SendsBillingWebhooks;

    private User $owner;

    private Project $project;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => true, 'codedna.challenges.enabled' => true]);
        $this->app->instance(AiProvider::class, new ScriptedAiProvider('valid'));
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator('pass'));
        $this->prefix = 'phpunit/'.strtolower((string) Str::ulid()).'/';
        config(['codedna.sources.key_prefix' => $this->prefix]);
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('sources');
        Storage::disk('sources')->deleteDirectory(rtrim($this->prefix, '/'));
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Sets what the user has used of a monthly quota in their current period. */
    private function used(QuotaKey $key, int $used, ?User $user = null): void
    {
        $context = app(BillingContextResolver::class)->resolve($user ?? $this->owner);
        DB::table('billing_usage_counters')->updateOrInsert(
            ['user_id' => $context->userId, 'quota_key' => $key->value, 'period_start' => $context->periodStart],
            ['used' => $used, 'updated_at' => now()],
        );
    }

    private function usedNow(QuotaKey $key): int
    {
        $context = app(BillingContextResolver::class)->resolve($this->owner);

        return (int) DB::table('billing_usage_counters')->where('user_id', $this->owner->id)->where('quota_key', $key->value)
            ->where('period_start', $context->periodStart)->value('used');
    }

    private function assertDenied(TestResponse $response, string $code): TestResponse
    {
        return $response->assertStatus(402)->assertJsonPath('error.code', $code)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id', 'details']]);
    }

    private function base(): string
    {
        return "/api/v1/projects/{$this->project->id}";
    }

    private function upload(string $content, ?string $key = null): TestResponse
    {
        $path = (new ZipBuilder)->file('app.py', $content)->save();
        $request = $this->asUser($this->owner);
        if ($key !== null) {
            $request = $request->withHeader('Idempotency-Key', $key);
        }

        return $request->post("{$this->base()}/source-snapshots", ['archive' => new UploadedFile($path, 'source.zip', 'application/zip', null, true)], ['Accept' => 'application/json']);
    }

    // Feature entitlements -------------------------------------------------

    public function test_the_free_plan_does_not_include_ai_assessment(): void
    {
        AssessmentFixtures::skillGaps($this->project);

        $this->assertDenied($this->asUser($this->owner)->postJson("{$this->base()}/assessments"), 'FEATURE_NOT_INCLUDED')
            ->assertJsonPath('error.details', ['feature' => 'AI_ASSESSMENT', 'plan' => 'FREE'])
            ->assertJsonPath('error.message', 'Your plan does not include this feature.');
        $this->assertSame(0, AiAssessment::query()->count());
    }

    public function test_the_server_switch_still_comes_first(): void
    {
        config(['codedna.ai.enabled' => false]);
        AssessmentFixtures::skillGaps($this->project);

        $this->asUser($this->owner)->postJson("{$this->base()}/assessments")->assertStatus(409)->assertJsonPath('error.code', 'AI_ASSESSMENT_DISABLED');
    }

    public function test_pro_includes_ai_assessment_and_charges_it_once(): void
    {
        BillingFixtures::pro($this->owner);
        AssessmentFixtures::skillGaps($this->project);

        $id = $this->asUser($this->owner)->postJson("{$this->base()}/assessments")->assertStatus(202)->json('data.id');
        // A repeated request returns the same assessment and is not charged again.
        $this->asUser($this->owner)->postJson("{$this->base()}/assessments")->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, $this->usedNow(QuotaKey::AiAssessments));
        $this->assertSame(1, BillingUsageEvent::query()->where('resource_id', $id)->where('outcome', 'ACCEPTED')->count());
    }

    public function test_an_inactive_subscription_explains_itself(): void
    {
        $this->useFakeBillingProvider();
        $subscription = BillingFixtures::subscribe($this->owner);
        $this->sendWebhook($this->billingEvent('SUBSCRIPTION_PAUSED', $this->customerRef($this->owner), $subscription->provider_subscription_ref, Carbon::now()))
            ->assertJsonPath('data.outcome', 'APPLIED');
        AssessmentFixtures::skillGaps($this->project);

        $this->assertDenied($this->asUser($this->owner)->postJson("{$this->base()}/assessments"), 'SUBSCRIPTION_INACTIVE')
            ->assertJsonPath('error.message', 'Your subscription is not active, so its features are paused.');
    }

    public function test_an_expired_period_without_renewal_falls_back_to_free(): void
    {
        BillingFixtures::subscribe($this->owner, start: Carbon::now()->subDays(31));
        AssessmentFixtures::skillGaps($this->project);

        $this->assertDenied($this->asUser($this->owner)->postJson("{$this->base()}/assessments"), 'SUBSCRIPTION_INACTIVE');
    }

    public function test_a_plan_without_the_learning_roadmap_cannot_generate_one(): void
    {
        $this->subscribeTo($this->customPlan(['PROJECTS'], []));
        AssessmentFixtures::skillGaps($this->project);

        $this->assertDenied($this->asUser($this->owner)->postJson("{$this->base()}/roadmaps", []), 'FEATURE_NOT_INCLUDED')
            ->assertJsonPath('error.details.feature', 'LEARNING_ROADMAP');
        // Reading what already exists is never gated.
        $this->asUser($this->owner)->getJson("{$this->base()}/history")->assertOk();
        $this->asUser($this->owner)->getJson("{$this->base()}/roadmaps")->assertOk();
    }

    public function test_a_plan_without_a_feature_refuses_every_action_that_needs_it(): void
    {
        $this->subscribeTo($this->customPlan(['PROJECTS', 'SOURCE_ANALYSIS'], ['ACTIVE_PROJECTS' => 3]));
        $gaps = AssessmentFixtures::skillGaps($this->project);
        FakeGitHub::configure()->fake();

        $this->assertDenied($this->asUser($this->owner)->postJson("{$this->base()}/challenges", ['skill_gap_snapshot_id' => $gaps->id]), 'FEATURE_NOT_INCLUDED')
            ->assertJsonPath('error.details.feature', 'CODING_CHALLENGES');
        $this->assertDenied($this->asUser($this->owner)->postJson('/api/v1/github/authorizations'), 'FEATURE_NOT_INCLUDED')
            ->assertJsonPath('error.details.feature', 'GITHUB_INTEGRATION');
        $this->assertSame(0, DB::table('challenge_instances')->count());
    }

    // Active-project gauge -------------------------------------------------

    public function test_the_free_plan_allows_three_active_projects_and_archiving_frees_one(): void
    {
        Project::factory()->for($this->owner)->count(2)->create();
        $create = fn (string $slug) => $this->asUser($this->owner)->postJson('/api/v1/projects', ['name' => $slug, 'slug' => $slug, 'source_type' => 'UPLOAD']);

        $this->assertDenied($create('fourth'), 'QUOTA_EXCEEDED')
            ->assertJsonPath('error.details', ['quota' => 'ACTIVE_PROJECTS', 'limit' => 3, 'used' => 3, 'resets_at' => null]);
        $this->assertSame(3, Project::query()->where('user_id', $this->owner->id)->count());
        $this->assertSame(['REJECTED'], BillingUsageEvent::query()->pluck('outcome')->map->value->all(), 'the refusal is recorded');

        $this->asUser($this->owner)->postJson("{$this->base()}/archive")->assertOk();
        $create('fourth')->assertCreated();
        // Archived projects stay readable.
        $this->asUser($this->owner)->getJson($this->base())->assertOk()->assertJsonPath('data.status', ProjectStatus::Archived->value);
    }

    // Monthly quotas -------------------------------------------------------

    public function test_the_last_analysis_unit_is_used_exactly_and_the_next_is_refused(): void
    {
        $first = SourceSnapshot::factory()->for($this->project)->create();
        $second = SourceSnapshot::factory()->for($this->project)->create();
        $this->used(QuotaKey::Analyses, 59);

        $run = $this->asUser($this->owner)->postJson("{$this->base()}/analyses", ['source_snapshot_id' => $first->id])->assertStatus(202)->json('data.id');
        // The equivalent run is returned again, free of charge, even at the limit.
        $this->asUser($this->owner)->postJson("{$this->base()}/analyses", ['source_snapshot_id' => $first->id])->assertOk()->assertJsonPath('data.id', $run);
        $this->assertDenied($this->asUser($this->owner)->postJson("{$this->base()}/analyses", ['source_snapshot_id' => $second->id]), 'QUOTA_EXCEEDED')
            ->assertJsonPath('error.details.quota', 'ANALYSES')->assertJsonPath('error.details.limit', 60)->assertJsonPath('error.details.used', 60);

        $this->assertSame([60, 1], [$this->usedNow(QuotaKey::Analyses), AnalysisRun::query()->count()]);
        $this->assertSame(['ACCEPTED', 'REJECTED'], BillingUsageEvent::query()->orderBy('created_at')->orderBy('outcome')->pluck('outcome')->map->value->all());
    }

    public function test_a_new_period_starts_from_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20T12:00:00Z'));
        $this->used(QuotaKey::Analyses, 60);
        $snapshot = SourceSnapshot::factory()->for($this->project)->create();
        $this->assertDenied($this->asUser($this->owner)->postJson("{$this->base()}/analyses", ['source_snapshot_id' => $snapshot->id]), 'QUOTA_EXCEEDED')
            ->assertJsonPath('error.details.resets_at', '2026-11-01T00:00:00Z');

        Carbon::setTestNow(Carbon::parse('2026-11-01T00:00:00Z'));
        $this->asUser($this->owner)->postJson("{$this->base()}/analyses", ['source_snapshot_id' => $snapshot->id])->assertStatus(202);
        $this->assertSame(1, $this->usedNow(QuotaKey::Analyses));
    }

    public function test_a_failed_analysis_is_refunded_once(): void
    {
        $snapshot = SourceSnapshot::factory()->for($this->project)->create();
        $id = $this->asUser($this->owner)->postJson("{$this->base()}/analyses", ['source_snapshot_id' => $snapshot->id])->json('data.id');
        $this->assertSame(1, $this->usedNow(QuotaKey::Analyses));

        AnalysisRun::query()->findOrFail($id)->markFailed('ANALYSIS_FAILED', 'failed');
        app(UsageService::class)->refund(QuotaKey::Analyses, 'analysis_run', $id);

        $this->assertSame(0, $this->usedNow(QuotaKey::Analyses));
        $this->assertSame(['ACCEPTED', 'REFUNDED'], BillingUsageEvent::query()->where('resource_id', $id)->orderBy('amount', 'desc')->pluck('outcome')->map->value->all());
        $this->assertSame(-1, BillingUsageEvent::query()->where('outcome', 'REFUNDED')->sole()->amount);
    }

    public function test_charging_is_idempotent_per_resource(): void
    {
        $id = strtolower((string) Str::ulid());
        DB::transaction(function () use ($id): void {
            app(UsageService::class)->consume($this->owner, QuotaKey::Analyses, 'analysis_run', $id);
            app(UsageService::class)->consume($this->owner, QuotaKey::Analyses, 'analysis_run', $id);
        });
        DB::transaction(fn () => app(UsageService::class)->consume($this->owner, QuotaKey::Analyses, 'analysis_run', $id));

        $this->assertSame(1, $this->usedNow(QuotaKey::Analyses));
        $this->assertSame(1, BillingUsageEvent::query()->count());
    }

    public function test_uploads_are_counted_in_number_and_bytes_and_refused_before_storing(): void
    {
        $this->upload('print(1)', 'upload-key-0001')->assertCreated();
        $snapshot = SourceSnapshot::query()->sole();
        $this->assertSame([1, $snapshot->size_bytes], [$this->usedNow(QuotaKey::SourceUploads), $this->usedNow(QuotaKey::SourceUploadBytes)]);

        $this->used(QuotaKey::SourceUploads, 30);
        $objects = Storage::disk('sources')->allFiles(rtrim($this->prefix, '/'));
        // A retry of the accepted upload still replays at the limit; a new upload is refused.
        $this->upload('print(1)', 'upload-key-0001')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertDenied($this->upload('print(2)'), 'QUOTA_EXCEEDED')->assertJsonPath('error.details.quota', 'SOURCE_UPLOADS');

        $this->assertSame(1, SourceSnapshot::query()->count());
        $this->assertSame($objects, Storage::disk('sources')->allFiles(rtrim($this->prefix, '/')), 'nothing was stored');
    }

    public function test_upload_bytes_are_a_separate_limit(): void
    {
        $this->used(QuotaKey::SourceUploadBytes, 500 * 1024 * 1024 - 10);
        // Refused before the archive is written: storage is never touched.
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldNotReceive('writeStream');
        Storage::set('sources', $disk);

        $this->assertDenied($this->upload(str_repeat('x', 200)), 'QUOTA_EXCEEDED')->assertJsonPath('error.details.quota', 'SOURCE_UPLOAD_BYTES');
        $this->assertSame(0, SourceSnapshot::query()->count());
    }

    public function test_challenge_submissions_are_charged_and_an_evaluation_error_is_refunded(): void
    {
        $gaps = AssessmentFixtures::skillGaps($this->project);
        $challenge = $this->asUser($this->owner)->postJson("{$this->base()}/challenges", ['skill_gap_snapshot_id' => $gaps->id])->assertCreated()->json('data.id');
        $submit = fn (string $source) => $this->asUser($this->owner)->postJson("{$this->base()}/challenges/{$challenge}/submissions", ['language' => 'python', 'source' => $source]);

        $id = $submit("def f():\n    return 1\n")->assertStatus(202)->json('data.id');
        $this->assertSame(1, $this->usedNow(QuotaKey::ChallengeSubmissions));
        $submission = ChallengeSubmission::query()->findOrFail($id);
        $submission->forceFill(['status' => 'ERROR', 'failure_code' => 'EVALUATION_FAILED', 'completed_at' => now()])->save();
        DB::table('challenge_instances')->where('id', $challenge)->update(['status' => 'ASSIGNED']);

        $this->assertSame(0, $this->usedNow(QuotaKey::ChallengeSubmissions), 'an evaluation error uses no quota');
        $this->used(QuotaKey::ChallengeSubmissions, 60);
        $this->assertDenied($submit("def f():\n    return 2\n"), 'QUOTA_EXCEEDED')->assertJsonPath('error.details.quota', 'CHALLENGE_SUBMISSIONS');
        $this->assertSame(1, ChallengeSubmission::query()->count());
    }

    public function test_github_imports_are_charged_and_a_failed_import_is_refunded(): void
    {
        FakeGitHub::configure()->fake();
        GitHubFixtures::account($this->owner);
        $connected = Project::factory()->for($this->owner)->create();
        GitHubFixtures::connection($connected);
        $url = "/api/v1/projects/{$connected->id}/github/imports";

        $id = $this->asUser($this->owner)->postJson($url)->assertStatus(202)->json('data.id');
        $this->assertSame(1, $this->usedNow(QuotaKey::GitHubImports));
        GitHubImport::query()->findOrFail($id)->forceFill(['status' => 'FAILED', 'failure_code' => 'GITHUB_IMPORT_FAILED', 'completed_at' => now()])->save();
        $this->assertSame(0, $this->usedNow(QuotaKey::GitHubImports));

        $this->used(QuotaKey::GitHubImports, 20);
        $this->assertDenied($this->asUser($this->owner)->postJson($url), 'QUOTA_EXCEEDED')->assertJsonPath('error.details.quota', 'GITHUB_IMPORTS');
        $this->assertSame(1, GitHubImport::query()->count());
        $this->assertTrue(class_exists(ImportGitHubSource::class));
    }

    public function test_an_unlimited_quota_never_refuses(): void
    {
        $this->subscribeTo($this->customPlan(['PROJECTS', 'SOURCE_ANALYSIS'], ['ANALYSES' => null]));
        $this->used(QuotaKey::Analyses, 1_000_000_000);
        $snapshot = SourceSnapshot::factory()->for($this->project)->create();

        $this->asUser($this->owner)->postJson("{$this->base()}/analyses", ['source_snapshot_id' => $snapshot->id])->assertStatus(202);
        $this->asUser($this->owner)->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.quotas.3', ['key' => 'ANALYSES', 'label' => 'Analyses', 'unit' => 'COUNT', 'period' => 'MONTHLY',
                'limit' => null, 'used' => 1_000_000_001, 'remaining' => null, 'unlimited' => true, 'resets_at' => app(BillingContextResolver::class)->resolve($this->owner)->periodEnd->toIso8601ZuluString()]);
    }

    public function test_a_zero_quota_refuses_even_the_first_unit(): void
    {
        $this->subscribeTo($this->customPlan(['PROJECTS', 'SOURCE_ANALYSIS'], ['ANALYSES' => 0]));
        $snapshot = SourceSnapshot::factory()->for($this->project)->create();

        $this->assertDenied($this->asUser($this->owner)->postJson("{$this->base()}/analyses", ['source_snapshot_id' => $snapshot->id]), 'QUOTA_EXCEEDED')
            ->assertJsonPath('error.details.limit', 0);
        $this->assertSame(0, AnalysisRun::query()->count());
    }

    /**
     * An extra ACTIVE plan with the given features and quota limits (missing quotas are 0).
     *
     * @param  list<string>  $features
     * @param  array<string, int|null>  $quotas
     */
    private function customPlan(array $features, array $quotas): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('billing_plans')->insert(['id' => $id, 'key' => 'CUSTOM', 'version' => '1.0.0', 'catalog_version' => 'test', 'name' => 'Custom', 'description' => 'test',
            'status' => 'ACTIVE', 'currency' => 'USD', 'monthly_price_minor' => 100, 'annual_price_minor' => 1000, 'fingerprint' => str_repeat('c', 64)]);
        foreach ($features as $feature) {
            DB::table('billing_plan_features')->insert(['billing_plan_id' => $id, 'feature' => $feature]);
        }
        foreach ($quotas as $key => $limit) {
            DB::table('billing_plan_quotas')->insert(['billing_plan_id' => $id, 'quota_key' => $key, 'limit' => $limit]);
        }
        $this->app->forgetScopedInstances();

        return 'CUSTOM';
    }

    private function subscribeTo(string $plan): void
    {
        BillingFixtures::subscribe($this->owner, $plan);
        $this->assertSame($plan, app(BillingContextResolver::class)->resolve($this->owner)->plan->key);
    }

    public function test_usage_outcomes_are_closed(): void
    {
        $this->assertSame(['ACCEPTED', 'REFUNDED', 'REJECTED'], array_map(fn (UsageOutcome $o): string => $o->value, UsageOutcome::cases()));
    }
}
