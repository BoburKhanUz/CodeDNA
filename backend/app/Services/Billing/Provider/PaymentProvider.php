<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

use App\Models\BillingPlan;
use App\Models\User;

/**
 * The boundary to a payment provider (docs/billing/billing-architecture.md#provider-boundary).
 *
 * Adapters talk to their provider and translate its webhooks into
 * provider-neutral ProviderEvents. They never write billing tables: every
 * state change goes through App\Services\Billing\SubscriptionService.
 * Methods a provider cannot support throw PaymentProviderException; callers
 * check supports() first.
 */
interface PaymentProvider
{
    /** A stable lowercase identifier, stored on customers and subscriptions. */
    public function name(): string;

    public function supports(ProviderCapability $capability): bool;

    /** Creates the provider customer for a user and returns its reference. */
    public function createCustomer(User $user): string;

    public function createCheckoutSession(string $customerRef, BillingPlan $plan, BillingInterval $interval): CheckoutSession;

    public function cancelSubscription(string $subscriptionRef, bool $atPeriodEnd): void;

    public function changeSubscription(string $subscriptionRef, BillingPlan $plan): void;

    public function getSubscription(string $subscriptionRef): ProviderSubscription;

    /**
     * Verifies authenticity (signature, timestamp) of a raw webhook body.
     *
     * @param  array<string, string>  $headers  lowercase header name => value
     */
    public function verifyWebhook(string $payload, array $headers): bool;

    /**
     * Translates a verified webhook body into a provider-neutral event.
     *
     * @throws WebhookRejected when the body is malformed or of an unsupported type
     */
    public function parseWebhook(string $payload): ProviderEvent;
}
