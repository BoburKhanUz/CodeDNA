<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Services\Billing\Provider\FakePaymentProvider;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Signed fake-provider webhooks through the real endpoint (Phase 23).
 *
 * @mixin TestCase
 */
trait SendsBillingWebhooks
{
    protected function useFakeBillingProvider(): void
    {
        config(['codedna.billing.provider' => FakePaymentProvider::NAME, 'codedna.billing.webhook_secret' => BillingFixtures::SECRET]);
        $this->app->forgetScopedInstances();
    }

    /** The user's customer reference at the fake provider. */
    protected function customerRef(User $user): string
    {
        return app(SubscriptionService::class)->customerFor($user, BillingFixtures::provider())->provider_customer_ref;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function billingEvent(string $type, string $customer, string $subscription, Carbon $at, array $overrides = []): array
    {
        return [
            'id' => 'evt_'.Str::random(24),
            'type' => $type,
            'occurred_at' => $at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'customer' => $customer,
            'subscription' => $subscription,
            ...$overrides,
        ];
    }

    /** A period of 30 days from $start, in the fake provider's format. */
    protected function period(Carbon $start, int $days = 30): array
    {
        return ['start' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'end' => $start->copy()->addDays($days)->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * @param  array<string, mixed>|string  $body
     */
    protected function sendWebhook(array|string $body, ?int $timestamp = null, ?string $secret = null, ?string $signature = null, string $provider = 'fake'): TestResponse
    {
        $payload = is_string($body) ? $body : (string) json_encode($body);
        $header = $signature ?? FakePaymentProvider::sign($payload, $secret ?? BillingFixtures::SECRET, $timestamp ?? Carbon::now()->getTimestamp());
        $this->flushHeaders();

        return $this->call('POST', "/api/v1/billing/webhooks/{$provider}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_BILLING_SIGNATURE' => $header,
        ], $payload);
    }
}
