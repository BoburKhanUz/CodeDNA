<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Provider-neutral subscription events. Provider adapters translate their
 * own webhook types into these; the billing domain applies only these, and
 * records each applied one in the subscription history.
 */
enum SubscriptionEventType: string
{
    case Activated = 'SUBSCRIPTION_ACTIVATED';
    case Renewed = 'SUBSCRIPTION_RENEWED';
    case PlanChanged = 'SUBSCRIPTION_PLAN_CHANGED';
    case Canceled = 'SUBSCRIPTION_CANCELED';
    case Expired = 'SUBSCRIPTION_EXPIRED';
    case PaymentFailed = 'PAYMENT_FAILED';
    case Paused = 'SUBSCRIPTION_PAUSED';
    case Resumed = 'SUBSCRIPTION_RESUMED';

    /** The log event name (docs/billing/billing-architecture.md#observability). */
    public function logName(): string
    {
        return match ($this) {
            self::Activated => 'billing.subscription.activated',
            self::Renewed => 'billing.subscription.renewed',
            self::PlanChanged => 'billing.subscription.plan_changed',
            self::Canceled => 'billing.subscription.canceled',
            self::Expired => 'billing.subscription.expired',
            self::PaymentFailed => 'billing.payment.failed',
            self::Paused => 'billing.subscription.paused',
            self::Resumed => 'billing.subscription.resumed',
        };
    }
}
