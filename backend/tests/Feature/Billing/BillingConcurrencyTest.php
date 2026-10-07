<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Analysis\StartAnalysis;
use App\Actions\Billing\ProcessBillingWebhook;
use App\Actions\Projects\CreateProject;
use App\Enums\AnalysisResultType;
use App\Enums\Billing\QuotaKey;
use App\Exceptions\ApiException;
use App\Models\BillingSubscription;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Billing\BillingContextResolver;
use App\Services\Billing\Provider\FakePaymentProvider;
use App\Services\Billing\SubscriptionService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BillingFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Phase 23: billing under real concurrency (one process and connection
 * each, against real PostgreSQL). Rows are committed, so this test does not
 * use RefreshDatabase's transaction; it deletes what it created.
 */
final class BillingConcurrencyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        config(['codedna.billing.provider' => FakePaymentProvider::NAME, 'codedna.billing.webhook_secret' => BillingFixtures::SECRET]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $projects = DB::table('projects')->where('user_id', $this->user->id)->pluck('id');
        DB::table('analysis_runs')->whereIn('project_id', $projects)->delete();
        DB::table('source_snapshots')->whereIn('project_id', $projects)->delete();
        DB::table('projects')->whereIn('id', $projects)->delete();
        BillingFixtures::forget([$this->user->id]);
        DB::table('users')->where('id', $this->user->id)->delete();
        parent::tearDown();
    }

    /**
     * @param  list<Closure(): string>  $racers
     * @return list<string> sorted results
     */
    private function race(array $racers): array
    {
        $dir = sys_get_temp_dir().'/codedna-billing-race-'.Str::random(8);
        mkdir($dir);
        DB::disconnect();
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach ($racers as $i => $racer) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    Queue::fake();
                    $this->app->forgetScopedInstances();
                    time_sleep_until($startAt);
                    $result = $racer();
                } catch (ApiException $e) {
                    $result = 'refused '.$e->errorCode->value;
                } catch (Throwable $e) {
                    $result = 'error '.$e::class.' '.$e->getMessage();
                }
                file_put_contents("{$dir}/{$i}", $result);
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();
        $results = [];
        foreach (array_keys($racers) as $i) {
            $results[] = (string) @file_get_contents("{$dir}/{$i}");
            @unlink("{$dir}/{$i}");
        }
        @rmdir($dir);
        sort($results);

        return $results;
    }

    private function counter(QuotaKey $key): int
    {
        $context = app(BillingContextResolver::class)->resolve($this->user);

        return (int) DB::table('billing_usage_counters')->where('user_id', $this->user->id)->where('quota_key', $key->value)
            ->where('period_start', $context->periodStart)->value('used');
    }

    public function test_concurrent_requests_cannot_both_take_the_last_unit(): void
    {
        $context = app(BillingContextResolver::class)->resolve($this->user);
        DB::table('billing_usage_counters')->insert(['user_id' => $this->user->id, 'quota_key' => 'ANALYSES', 'period_start' => $context->periodStart, 'used' => 59, 'updated_at' => now()]);
        $racers = [];
        foreach (range(1, 3) as $i) {
            $project = Project::factory()->for($this->user)->create();
            $snapshot = SourceSnapshot::factory()->for($project)->create();
            $racers[] = fn (): string => app(StartAnalysis::class)->handle(Project::query()->findOrFail($project->id), User::query()->findOrFail($this->user->id), $snapshot->id, AnalysisResultType::Foundation)->created ? 'started' : 'reused';
        }

        $results = $this->race($racers);

        $this->assertSame(['refused QUOTA_EXCEEDED', 'refused QUOTA_EXCEEDED', 'started'], $results);
        $this->assertSame(60, $this->counter(QuotaKey::Analyses), 'never above the limit, never negative');
        $this->assertSame(1, DB::table('analysis_runs')->whereIn('project_id', Project::query()->where('user_id', $this->user->id)->select('id'))->count());
        $this->assertSame(1, DB::table('billing_usage_events')->where('user_id', $this->user->id)->where('outcome', 'ACCEPTED')->count());
        $this->assertSame(2, DB::table('billing_usage_events')->where('user_id', $this->user->id)->where('outcome', 'REJECTED')->count());
    }

    public function test_concurrent_project_creations_cannot_exceed_the_active_project_limit(): void
    {
        Project::factory()->for($this->user)->count(2)->create();
        $racers = array_map(fn (int $i): Closure => fn (): string => app(CreateProject::class)
            ->handle(User::query()->findOrFail($this->user->id), ['name' => "P{$i}", 'slug' => "race-{$i}", 'source_type' => 'UPLOAD'])->slug ? 'created' : '', range(1, 4));

        $this->assertSame(['created', 'refused QUOTA_EXCEEDED', 'refused QUOTA_EXCEEDED', 'refused QUOTA_EXCEEDED'], $this->race($racers));
        $this->assertSame(3, Project::query()->where('user_id', $this->user->id)->count());
    }

    private function signedActivation(string $customer, string $subscription, string $eventId, Carbon $at): array
    {
        $payload = (string) json_encode(['id' => $eventId, 'type' => 'SUBSCRIPTION_ACTIVATED', 'occurred_at' => $at->format('Y-m-d\TH:i:s\Z'), 'customer' => $customer,
            'subscription' => $subscription, 'plan' => ['key' => 'PRO'], 'period' => ['start' => $at->format('Y-m-d\TH:i:s\Z'), 'end' => $at->copy()->addDays(30)->format('Y-m-d\TH:i:s\Z')]]);

        return [$payload, ['x-billing-signature' => FakePaymentProvider::sign($payload, BillingFixtures::SECRET, Carbon::now()->getTimestamp())]];
    }

    public function test_concurrent_deliveries_of_one_event_are_processed_once(): void
    {
        $customer = app(SubscriptionService::class)->customerFor($this->user, BillingFixtures::provider())->provider_customer_ref;
        [$payload, $headers] = $this->signedActivation($customer, 'sub_race', 'evt_race_'.Str::random(8), Carbon::now()->subMinute());
        $racers = array_fill(0, 4, fn (): string => app(ProcessBillingWebhook::class)->handle('fake', $payload, $headers)['status']);

        $this->assertSame(['duplicate', 'duplicate', 'duplicate', 'processed'], $this->race($racers));
        $this->assertSame(1, BillingSubscription::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(1, DB::table('billing_subscription_events')->where('user_id', $this->user->id)->count());
    }

    public function test_concurrent_activations_grant_one_subscription(): void
    {
        $customer = app(SubscriptionService::class)->customerFor($this->user, BillingFixtures::provider())->provider_customer_ref;
        $racers = array_map(function (int $i) use ($customer): Closure {
            [$payload, $headers] = $this->signedActivation($customer, "sub_race_{$i}", "evt_race_{$i}_".Str::random(8), Carbon::now()->subMinute());

            return fn (): string => app(ProcessBillingWebhook::class)->handle('fake', $payload, $headers)['outcome']->value;
        }, range(1, 3));

        $this->assertSame(['APPLIED', 'CONFLICT', 'CONFLICT'], $this->race($racers));
        $this->assertSame(1, BillingSubscription::query()->where('user_id', $this->user->id)->count());
    }

    public function test_a_renewal_racing_a_cancellation_never_revives_the_subscription(): void
    {
        $subscription = BillingFixtures::subscribe($this->user, start: Carbon::now()->subDays(29));
        $customer = DB::table('billing_customers')->where('user_id', $this->user->id)->value('provider_customer_ref');
        $event = function (string $type, Carbon $at, array $extra = []) use ($customer, $subscription): array {
            $payload = (string) json_encode(['id' => 'evt_'.Str::random(16), 'type' => $type, 'occurred_at' => $at->format('Y-m-d\TH:i:s\Z'),
                'customer' => $customer, 'subscription' => $subscription->provider_subscription_ref, ...$extra]);

            return [$payload, ['x-billing-signature' => FakePaymentProvider::sign($payload, BillingFixtures::SECRET, Carbon::now()->getTimestamp())]];
        };
        $renewedAt = Carbon::now()->subMinutes(2);
        [$renewal, $renewalHeaders] = $event('SUBSCRIPTION_RENEWED', $renewedAt, ['period' => ['start' => $renewedAt->format('Y-m-d\TH:i:s\Z'), 'end' => $renewedAt->copy()->addDays(30)->format('Y-m-d\TH:i:s\Z')]]);
        [$cancel, $cancelHeaders] = $event('SUBSCRIPTION_CANCELED', Carbon::now()->subMinute());

        $results = $this->race([
            fn (): string => 'renewal '.app(ProcessBillingWebhook::class)->handle('fake', $renewal, $renewalHeaders)['outcome']->value,
            fn (): string => 'cancel '.app(ProcessBillingWebhook::class)->handle('fake', $cancel, $cancelHeaders)['outcome']->value,
        ]);

        $this->assertSame('cancel APPLIED', $results[0]);
        $this->assertContains($results[1], ['renewal APPLIED', 'renewal STALE']);
        $this->assertSame('CANCELED', BillingSubscription::query()->findOrFail($subscription->id)->status->value);
        $this->assertSame('FREE', app(BillingContextResolver::class)->resolve($this->user)->plan->key);
    }
}
