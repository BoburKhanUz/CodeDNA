<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionEvent;
use App\Models\BillingWebhookEvent;
use App\Models\User;
use App\Services\Billing\BillingContextResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SendsBillingWebhooks;
use Tests\TestCase;

/**
 * Phase 23: the subscription state machine through verified provider
 * events (docs/billing/subscription-state-machine.md).
 */
final class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SendsBillingWebhooks;

    private User $user;

    private string $customer;

    private Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeBillingProvider();
        $this->user = User::factory()->create();
        $this->customer = $this->customerRef($this->user);
        $this->start = Carbon::parse('2026-10-01T00:00:00Z');
        Carbon::setTestNow($this->start->copy()->addHour());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(string $type, Carbon $at, array $overrides = [], string $subscription = 'sub_main'): array
    {
        return $this->billingEvent($type, $this->customer, $subscription, $at, $overrides);
    }

    private function activate(?Carbon $at = null, string $plan = 'PRO', string $subscription = 'sub_main', array $extra = []): array
    {
        $at ??= $this->start;

        return $this->sendWebhook($this->event('SUBSCRIPTION_ACTIVATED', $at, ['plan' => ['key' => $plan], 'period' => $this->period($at), ...$extra], $subscription))
            ->assertOk()->json('data');
    }

    private function subscription(): BillingSubscription
    {
        return BillingSubscription::query()->where('user_id', $this->user->id)->orderByDesc('created_at')->firstOrFail();
    }

    private function plan(): string
    {
        return app(BillingContextResolver::class)->resolve($this->user)->plan->key;
    }

    /** @return list<string> */
    private function history(): array
    {
        return BillingSubscriptionEvent::query()->where('user_id', $this->user->id)->orderBy('occurred_at')->orderBy('created_at')
            ->get()->map(fn (BillingSubscriptionEvent $e): string => $e->type->value.':'.($e->from_status?->value ?? '-').'>'.$e->to_status->value)->all();
    }

    public function test_activation_grants_the_paid_plan_and_records_history(): void
    {
        $this->assertSame('FREE', $this->plan());

        $this->assertSame(['type' => 'billing_webhook_receipt', 'status' => 'processed', 'outcome' => 'APPLIED'], $this->activate());

        $this->assertSame('PRO', $this->plan());
        $this->assertSame(SubscriptionStatus::Active, $this->subscription()->status);
        $this->assertSame(['SUBSCRIPTION_ACTIVATED:->ACTIVE'], $this->history());
    }

    public function test_a_trial_grants_the_plan_until_the_period_ends(): void
    {
        $this->activate(extra: ['trial_end' => $this->start->copy()->addDays(14)->format('Y-m-d\TH:i:s\Z')]);

        $this->assertSame(SubscriptionStatus::Trialing, $this->subscription()->status);
        $this->assertSame('PRO', $this->plan());
    }

    public function test_renewal_moves_the_period_and_a_lapsed_period_stops_granting(): void
    {
        $this->activate();
        Carbon::setTestNow($this->start->copy()->addDays(31));
        $this->assertSame('FREE', $this->plan(), 'no renewal received: the paid period is over');

        $renewal = $this->start->copy()->addDays(30);
        $this->sendWebhook($this->event('SUBSCRIPTION_RENEWED', $renewal, ['period' => $this->period($renewal)]))->assertJsonPath('data.outcome', 'APPLIED');

        $this->assertSame('PRO', $this->plan());
        $this->assertTrue($this->subscription()->current_period_end->equalTo($renewal->copy()->addDays(30)));
    }

    public function test_a_failed_payment_keeps_the_paid_period_then_stops_until_paid(): void
    {
        $this->activate();
        $this->sendWebhook($this->event('PAYMENT_FAILED', $this->start->copy()->addDays(29)))->assertJsonPath('data.outcome', 'APPLIED');

        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription()->status);
        $this->assertSame('PRO', $this->plan(), 'the period already paid for still applies');
        Carbon::setTestNow($this->start->copy()->addDays(30)->addSecond());
        $this->assertSame('FREE', $this->plan(), 'a failed payment never extends access');

        $paid = $this->start->copy()->addDays(30)->addHour();
        $this->sendWebhook($this->event('SUBSCRIPTION_RENEWED', $paid, ['period' => $this->period($paid)]))->assertJsonPath('data.outcome', 'APPLIED');
        $this->assertSame([SubscriptionStatus::Active, 'PRO'], [$this->subscription()->status, $this->plan()]);
    }

    public function test_upgrade_and_downgrade_change_the_plan_and_keep_history(): void
    {
        $basic = $this->extraPlan('BASIC', 500);
        $this->activate(plan: 'BASIC');
        $this->assertSame('BASIC', $this->plan());

        $this->sendWebhook($this->event('SUBSCRIPTION_PLAN_CHANGED', $this->start->copy()->addDay(), ['plan' => ['key' => 'PRO', 'version' => '1.0.0']]))->assertJsonPath('data.outcome', 'APPLIED');
        $this->assertSame('PRO', $this->plan());
        $this->sendWebhook($this->event('SUBSCRIPTION_PLAN_CHANGED', $this->start->copy()->addDays(2), ['plan' => ['key' => 'BASIC']]))->assertJsonPath('data.outcome', 'APPLIED');
        $this->assertSame('BASIC', $this->plan());

        $changes = BillingSubscriptionEvent::query()->where('type', 'SUBSCRIPTION_PLAN_CHANGED')->orderBy('occurred_at')->get();
        $pro = BillingPlan::query()->where('key', 'PRO')->sole()->id;
        $this->assertSame([[$basic, $pro], [$pro, $basic]], $changes->map(fn ($e) => [$e->from_plan_id, $e->to_plan_id])->all());
    }

    public function test_cancel_at_period_end_keeps_access_until_the_end_then_expires(): void
    {
        $this->activate();
        $this->sendWebhook($this->event('SUBSCRIPTION_CANCELED', $this->start->copy()->addDays(3), ['at_period_end' => true]))->assertJsonPath('data.outcome', 'APPLIED');

        $subscription = $this->subscription();
        $this->assertSame([SubscriptionStatus::Active, true], [$subscription->status, $subscription->cancel_at_period_end]);
        $this->assertSame('PRO', $this->plan());

        Carbon::setTestNow($this->start->copy()->addDays(30));
        $this->assertSame('FREE', $this->plan(), 'access ends exactly at the period end');
        $this->artisan('billing:expire-subscriptions')->expectsOutput('Subscriptions expired: 1')->assertSuccessful();
        $this->artisan('billing:expire-subscriptions')->expectsOutput('Subscriptions expired: 0')->assertSuccessful();
        $this->assertSame(SubscriptionStatus::Expired, $this->subscription()->status);
        $this->assertSame(['SUBSCRIPTION_ACTIVATED:->ACTIVE', 'SUBSCRIPTION_CANCELED:ACTIVE>ACTIVE', 'SUBSCRIPTION_EXPIRED:ACTIVE>EXPIRED'], $this->history());
    }

    public function test_immediate_cancellation_ends_access_and_the_user_returns_to_free(): void
    {
        $this->activate();
        $this->sendWebhook($this->event('SUBSCRIPTION_CANCELED', $this->start->copy()->addDays(3)))->assertJsonPath('data.outcome', 'APPLIED');

        $this->assertSame([SubscriptionStatus::Canceled, 'FREE'], [$this->subscription()->status, $this->plan()]);
        $this->assertNotNull($this->subscription()->ended_at);
    }

    public function test_pause_stops_access_and_resume_restores_it(): void
    {
        $this->activate();
        $this->sendWebhook($this->event('SUBSCRIPTION_PAUSED', $this->start->copy()->addDay()))->assertJsonPath('data.outcome', 'APPLIED');
        $this->assertSame([SubscriptionStatus::Paused, 'FREE'], [$this->subscription()->status, $this->plan()]);

        $this->sendWebhook($this->event('SUBSCRIPTION_RESUMED', $this->start->copy()->addDays(2)))->assertJsonPath('data.outcome', 'APPLIED');
        $this->assertSame([SubscriptionStatus::Active, 'PRO'], [$this->subscription()->status, $this->plan()]);
    }

    public function test_terminal_subscriptions_never_change_again(): void
    {
        $this->activate();
        $this->sendWebhook($this->event('SUBSCRIPTION_EXPIRED', $this->start->copy()->addDays(30)))->assertJsonPath('data.outcome', 'APPLIED');

        foreach (['SUBSCRIPTION_RENEWED' => ['period' => $this->period($this->start->copy()->addDays(31))], 'SUBSCRIPTION_RESUMED' => [], 'PAYMENT_FAILED' => [],
            'SUBSCRIPTION_PLAN_CHANGED' => ['plan' => ['key' => 'PRO']]] as $type => $extra) {
            $this->sendWebhook($this->event($type, $this->start->copy()->addDays(31), $extra))->assertJsonPath('data.outcome', 'INVALID_TRANSITION');
        }
        $this->assertSame([SubscriptionStatus::Expired, 'FREE'], [$this->subscription()->status, $this->plan()]);
        $subscription = $this->subscription();
        $this->assertThrows(fn () => $subscription->forceFill(['status' => SubscriptionStatus::Active])->save(), DomainRuleViolation::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('billing_subscriptions')->where('id', $subscription->id)->update(['status' => 'ACTIVE', 'ended_at' => null])), QueryException::class);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $this->activate();
        // RESUMED only follows PAUSED; a second activation of the same subscription is not a renewal.
        $this->sendWebhook($this->event('SUBSCRIPTION_RESUMED', $this->start->copy()->addDay()))->assertJsonPath('data.outcome', 'INVALID_TRANSITION');
        $this->sendWebhook($this->event('SUBSCRIPTION_ACTIVATED', $this->start->copy()->addDay(), ['plan' => ['key' => 'PRO'], 'period' => $this->period($this->start)]))->assertJsonPath('data.outcome', 'INVALID_TRANSITION');
        // A renewal must not move the period backwards.
        $this->sendWebhook($this->event('SUBSCRIPTION_RENEWED', $this->start->copy()->addDay(), ['period' => $this->period($this->start->copy()->subDays(30))]))->assertJsonPath('data.outcome', 'INVALID_TRANSITION');

        $this->assertSame(['SUBSCRIPTION_ACTIVATED:->ACTIVE'], $this->history());
    }

    public function test_a_paused_subscription_only_resumes_or_ends(): void
    {
        $this->activate();
        $this->sendWebhook($this->event('SUBSCRIPTION_PAUSED', $this->start->copy()->addDay()))->assertJsonPath('data.outcome', 'APPLIED');

        // A failed payment while paused is not a transition: PAUSED never becomes PAST_DUE.
        $this->sendWebhook($this->event('PAYMENT_FAILED', $this->start->copy()->addDays(2)))->assertJsonPath('data.outcome', 'INVALID_TRANSITION');

        $this->assertSame(SubscriptionStatus::Paused, $this->subscription()->status);
        $this->assertSame(['SUBSCRIPTION_ACTIVATED:->ACTIVE', 'SUBSCRIPTION_PAUSED:ACTIVE>PAUSED'], $this->history());
    }

    public function test_the_transition_table_is_the_documented_one(): void
    {
        $table = [];
        foreach (SubscriptionStatus::cases() as $from) {
            $table[$from->value] = array_values(array_map(fn (SubscriptionStatus $s): string => $s->value,
                array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $to): bool => $from->canTransitionTo($to))));
        }

        // docs/billing/subscription-state-machine.md#transitions
        $this->assertSame([
            'TRIALING' => ['ACTIVE', 'PAST_DUE', 'PAUSED', 'CANCELED', 'EXPIRED'],
            'ACTIVE' => ['ACTIVE', 'PAST_DUE', 'PAUSED', 'CANCELED', 'EXPIRED'],
            'PAST_DUE' => ['ACTIVE', 'PAUSED', 'CANCELED', 'EXPIRED'],
            'PAUSED' => ['ACTIVE', 'CANCELED', 'EXPIRED'],
            'CANCELED' => [],
            'EXPIRED' => [],
        ], $table);
        $this->assertSame(['TRIALING', 'ACTIVE', 'PAST_DUE'], array_values(array_map(fn (SubscriptionStatus $s): string => $s->value,
            array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s): bool => $s->canGrant()))));
    }

    public function test_out_of_order_events_never_revert_newer_state(): void
    {
        $this->activate();
        $canceled = $this->start->copy()->addDays(5);
        $this->sendWebhook($this->event('SUBSCRIPTION_CANCELED', $canceled))->assertJsonPath('data.outcome', 'APPLIED');

        // An older renewal and an older plan change arrive late.
        $this->sendWebhook($this->event('SUBSCRIPTION_RENEWED', $this->start->copy()->addDays(2), ['period' => $this->period($this->start->copy()->addDays(2))]))->assertJsonPath('data.outcome', 'STALE');
        $this->sendWebhook($this->event('PAYMENT_FAILED', $this->start->copy()->addDays(4)))->assertJsonPath('data.outcome', 'STALE');

        $this->assertSame([SubscriptionStatus::Canceled, 'FREE'], [$this->subscription()->status, $this->plan()]);
    }

    public function test_a_cancellation_that_overtakes_its_activation_still_wins(): void
    {
        // Event B (canceled, newer) is delivered before event A (activated, older).
        $this->sendWebhook($this->event('SUBSCRIPTION_CANCELED', $this->start->copy()->addDays(2)))->assertJsonPath('data.outcome', 'DEFERRED');
        $this->assertSame('FREE', $this->plan());

        $this->activate();

        $this->assertSame([SubscriptionStatus::Canceled, 'FREE'], [$this->subscription()->status, $this->plan()]);
        $this->assertSame(['SUBSCRIPTION_ACTIVATED:->ACTIVE', 'SUBSCRIPTION_CANCELED:ACTIVE>CANCELED'], $this->history());
        $this->assertSame(['APPLIED', 'APPLIED'], BillingWebhookEvent::query()->orderBy('occurred_at')->pluck('outcome')->map->value->all());
    }

    public function test_a_renewal_that_overtakes_its_activation_keeps_the_newer_period(): void
    {
        $renewal = $this->start->copy()->addDays(30);
        $this->sendWebhook($this->event('SUBSCRIPTION_RENEWED', $renewal, ['period' => $this->period($renewal)]))->assertJsonPath('data.outcome', 'DEFERRED');
        $old = $this->sendWebhook($this->event('PAYMENT_FAILED', $this->start->copy()->subDay()))->json('data.outcome');

        $this->activate();

        $this->assertSame('DEFERRED', $old);
        $this->assertTrue($this->subscription()->current_period_end->equalTo($renewal->copy()->addDays(30)));
        $this->assertSame(['STALE', 'APPLIED', 'APPLIED'], BillingWebhookEvent::query()->orderBy('occurred_at')->pluck('outcome')->map->value->all());
    }

    public function test_free_reserved_or_unknown_plans_are_never_activated(): void
    {
        foreach (['FREE', 'TEAM_READY', 'ENTERPRISE'] as $i => $plan) {
            $this->assertSame('UNKNOWN_PLAN', $this->activate(plan: $plan, subscription: "sub_{$i}")['outcome']);
        }
        $this->assertSame(0, BillingSubscription::query()->count());
        $this->sendWebhook($this->event('SUBSCRIPTION_ACTIVATED', $this->start, ['plan' => ['key' => 'PRO', 'version' => '0.0.1'], 'period' => $this->period($this->start)]))->assertJsonPath('data.outcome', 'UNKNOWN_PLAN');
    }

    public function test_a_second_current_subscription_is_a_conflict_not_a_second_grant(): void
    {
        $this->activate();
        $this->assertSame('CONFLICT', $this->activate(subscription: 'sub_other')['outcome']);

        $this->assertSame(1, BillingSubscription::query()->where('user_id', $this->user->id)->count());
    }

    public function test_events_for_unknown_customers_or_other_users_subscriptions_change_nothing(): void
    {
        $this->activate();
        $stranger = User::factory()->create();
        $strangerRef = $this->customerRef($stranger);

        $this->sendWebhook($this->billingEvent('SUBSCRIPTION_CANCELED', 'cus_nobody', 'sub_main', $this->start->copy()->addDay()))->assertJsonPath('data.outcome', 'UNKNOWN_CUSTOMER');
        // Another user's customer cannot act on this user's subscription.
        $this->sendWebhook($this->billingEvent('SUBSCRIPTION_CANCELED', $strangerRef, 'sub_main', $this->start->copy()->addDay()))->assertJsonPath('data.outcome', 'UNKNOWN_SUBSCRIPTION');

        $this->assertSame([SubscriptionStatus::Active, 'PRO'], [$this->subscription()->status, $this->plan()]);
    }

    /** An extra ACTIVE paid plan, for plan changes (the shipped catalog has one paid plan). */
    private function extraPlan(string $key, int $price): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('billing_plans')->insert(['id' => $id, 'key' => $key, 'version' => '1.0.0', 'catalog_version' => 'test', 'name' => $key, 'description' => 'test',
            'status' => 'ACTIVE', 'currency' => 'USD', 'monthly_price_minor' => $price, 'annual_price_minor' => $price * 10, 'fingerprint' => str_repeat('a', 64)]);
        DB::table('billing_plan_features')->insert(['billing_plan_id' => $id, 'feature' => 'PROJECTS']);
        $this->app->forgetScopedInstances();

        return $id;
    }
}
