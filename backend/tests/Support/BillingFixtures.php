<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Billing\SubscriptionEventType;
use App\Enums\Billing\WebhookOutcome;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\Provider\FakePaymentProvider;
use App\Services\Billing\Provider\ProviderEvent;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Billing for tests, only through the billing domain (Phase 23): a paid plan
 * is a real subscription activated by a provider-neutral event, exactly as a
 * verified webhook would activate it. There is no bypass.
 */
final class BillingFixtures
{
    public const SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function provider(): FakePaymentProvider
    {
        return new FakePaymentProvider(self::SECRET);
    }

    /**
     * Subscribes the user to a plan (default PRO) for a 30-day period starting now.
     */
    public static function subscribe(User $user, string $plan = 'PRO', ?Carbon $start = null, ?Carbon $trialEnds = null): BillingSubscription
    {
        $start ??= Carbon::now()->subMinute();
        $customer = app(SubscriptionService::class)->customerFor($user, self::provider());
        [$outcome, $subscription] = app(SubscriptionService::class)->apply(new ProviderEvent(
            provider: FakePaymentProvider::NAME,
            eventId: 'evt_'.Str::random(20),
            type: SubscriptionEventType::Activated,
            occurredAt: $start,
            customerRef: $customer->provider_customer_ref,
            subscriptionRef: 'sub_'.Str::random(20),
            planKey: $plan,
            periodStart: $start,
            periodEnd: $start->copy()->addDays(30),
            trialEndsAt: $trialEnds,
        ));
        Assert::assertSame(WebhookOutcome::Applied, $outcome, 'the test subscription was not activated');
        Assert::assertNotNull($subscription);

        return $subscription;
    }

    /** A user on the PRO plan. */
    public static function pro(User $user): User
    {
        self::subscribe($user);

        return $user;
    }

    /**
     * Deletes the billing rows of users created by tests that commit their
     * rows (concurrency tests). The models refuse deletes; cleanup uses the
     * query builder, as for the other immutable tables.
     *
     * @param  list<string>|Collection<int, string>  $userIds
     */
    public static function forget(iterable $userIds): void
    {
        $ids = collect($userIds)->values()->all();
        if ($ids === []) {
            return;
        }
        DB::table('billing_usage_events')->whereIn('user_id', $ids)->delete();
        DB::table('billing_usage_counters')->whereIn('user_id', $ids)->delete();
        DB::table('billing_subscription_events')->whereIn('user_id', $ids)->delete();
        $subscriptions = DB::table('billing_subscriptions')->whereIn('user_id', $ids)->pluck('id');
        $customers = DB::table('billing_customers')->whereIn('user_id', $ids)->pluck('provider_customer_ref');
        DB::table('billing_webhook_events')->where(fn ($q) => $q->whereIn('billing_subscription_id', $subscriptions)
            ->orWhereIn('provider_customer_ref', $customers))->delete();
        DB::table('billing_subscriptions')->whereIn('user_id', $ids)->delete();
        DB::table('billing_customers')->whereIn('user_id', $ids)->delete();
    }
}
