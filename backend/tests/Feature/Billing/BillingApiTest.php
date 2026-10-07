<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\QuotaKey;
use App\Models\BillingCustomer;
use App\Models\BillingSubscription;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

/**
 * Phase 23: the read-only billing API. Always the caller's own billing;
 * nothing a client sends can choose a plan or change a quota.
 */
final class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-15T10:00:00Z'));
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_new_user_is_on_the_free_plan_by_fallback(): void
    {
        $data = $this->asUser($this->user)->getJson('/api/v1/billing')->assertOk()->json('data');

        $this->assertSame(['key' => 'FREE', 'version' => '1.0.0', 'name' => 'Free'], $data['plan']);
        $this->assertSame(['FREE_FALLBACK', 'FREE', null, null], [$data['source'], $data['status'], $data['subscription'], $data['inactive_subscription']]);
        $this->assertSame(['start' => '2026-10-01T00:00:00Z', 'end' => '2026-11-01T00:00:00Z'], $data['period']);
        $this->assertSame(['AI_ASSESSMENT'], array_values(array_map(fn ($e) => $e['feature'], array_filter($data['entitlements'], fn ($e) => ! $e['included']))));
        $this->assertSame(array_map(fn (QuotaKey $k): string => $k->value, QuotaKey::cases()), array_column($data['quotas'], 'key'));
        $this->assertSame(['limit' => 3, 'used' => 0, 'remaining' => 3, 'unlimited' => false, 'resets_at' => null],
            array_intersect_key($data['quotas'][0], array_flip(['limit', 'used', 'remaining', 'unlimited', 'resets_at'])));
    }

    public function test_the_overview_counts_what_was_used(): void
    {
        $project = Project::factory()->for($this->user)->create();
        $snapshot = SourceSnapshot::factory()->for($project)->create();
        $this->asUser($this->user)->postJson("/api/v1/projects/{$project->id}/analyses", ['source_snapshot_id' => $snapshot->id])->assertStatus(202);

        $quotas = collect($this->asUser($this->user)->getJson('/api/v1/billing')->json('data.quotas'))->keyBy('key');

        $this->assertSame([1, 3, 2], [$quotas['ACTIVE_PROJECTS']['used'], $quotas['ACTIVE_PROJECTS']['limit'], $quotas['ACTIVE_PROJECTS']['remaining']]);
        $this->assertSame([1, 60, 59, '2026-11-01T00:00:00Z'], [$quotas['ANALYSES']['used'], $quotas['ANALYSES']['limit'], $quotas['ANALYSES']['remaining'], $quotas['ANALYSES']['resets_at']]);
        $usage = $this->asUser($this->user)->getJson('/api/v1/billing/usage')->assertOk();
        $usage->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.quota', 'ANALYSES')->assertJsonPath('data.0.outcome', 'ACCEPTED')->assertJsonPath('data.0.resource_type', 'analysis_run');
    }

    public function test_a_subscriber_sees_their_plan_period_and_history_without_provider_references(): void
    {
        $subscription = BillingFixtures::subscribe($this->user);

        $overview = $this->asUser($this->user)->getJson('/api/v1/billing')->assertOk();
        $overview->assertJsonPath('data.plan.key', 'PRO')->assertJsonPath('data.source', 'SUBSCRIPTION')->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.subscription.grants_access', true)->assertJsonPath('data.subscription.cancel_at_period_end', false);
        $state = $this->asUser($this->user)->getJson('/api/v1/billing/subscription')->assertOk();
        $state->assertJsonPath('data.current.id', $subscription->id)->assertJsonPath('data.history.0.event', 'SUBSCRIPTION_ACTIVATED');

        $customer = (string) BillingCustomer::query()->sole()->provider_customer_ref;
        foreach ([$overview, $state] as $response) {
            $body = (string) $response->getContent();
            $this->assertStringNotContainsString($subscription->provider_subscription_ref, $body);
            $this->assertStringNotContainsString($customer, $body);
            $this->assertStringNotContainsString('payload', $body);
        }
    }

    public function test_the_plan_catalog_is_listed_with_integer_prices(): void
    {
        $plans = $this->asUser($this->user)->getJson('/api/v1/billing/plans')->assertOk()->json('data');

        $this->assertSame(['FREE', 'PRO', 'TEAM_READY'], array_column($plans, 'key'));
        $this->assertSame([['monthly_minor' => 0, 'annual_minor' => 0], ['monthly_minor' => 1500, 'annual_minor' => 15000], ['monthly_minor' => null, 'annual_minor' => null]], array_column($plans, 'prices'));
        $this->assertSame([true, true, false], array_column($plans, 'available'));
        $this->assertSame(['USD', 'USD', 'USD'], array_column($plans, 'currency'));
    }

    public function test_billing_is_always_the_callers_own(): void
    {
        $other = User::factory()->create();
        BillingFixtures::subscribe($other);

        $this->asUser($this->user)->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'FREE');
        $this->asUser($this->user)->getJson('/api/v1/billing/subscription')->assertExactJson(['data' => ['type' => 'billing_subscription_state', 'current' => null, 'history' => []]]);
        $this->asUser($this->user)->getJson('/api/v1/billing/usage')->assertJsonPath('meta.total', 0);
        // A user ID in the query is not a way in.
        $this->asUser($this->user)->getJson("/api/v1/billing?user_id={$other->id}")->assertJsonPath('data.plan.key', 'FREE');
    }

    public function test_no_request_can_choose_a_plan_or_change_a_quota(): void
    {
        $tamper = ['plan' => 'PRO', 'plan_id' => 'x', 'status' => 'ACTIVE', 'quota' => ['ANALYSES' => 999999], 'provider_customer_ref' => 'cus_x'];
        foreach (['/api/v1/billing', '/api/v1/billing/plans', '/api/v1/billing/subscription', '/api/v1/billing/usage'] as $path) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $this->asUser($this->user)->json($method, $path, $tamper)->assertStatus(405);
            }
        }
        $this->asUser($this->user)->withHeaders(['X-Plan' => 'PRO'])->getJson('/api/v1/billing?plan=PRO')->assertJsonPath('data.plan.key', 'FREE');

        $this->assertSame(0, BillingSubscription::query()->count());
    }

    public function test_the_usage_list_validates_its_query(): void
    {
        $this->asUser($this->user)->getJson('/api/v1/billing/usage?per_page=1000')->assertUnprocessable();
    }

    public function test_billing_needs_a_session_and_is_rate_limited_per_user(): void
    {
        $this->getJson('/api/v1/billing')->assertUnauthorized();
        $limit = (int) config('codedna.rate_limits.billing_read_per_minute');
        for ($i = 0; $i < $limit; $i++) {
            $this->asUser($this->user)->getJson('/api/v1/billing/plans')->assertOk();
        }
        $this->asUser($this->user)->getJson('/api/v1/billing/plans')->assertStatus(429);
        $this->asUser(User::factory()->create())->getJson('/api/v1/billing/plans')->assertOk();
    }
}
