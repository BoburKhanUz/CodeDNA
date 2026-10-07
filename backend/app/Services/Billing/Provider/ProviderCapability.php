<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

/**
 * What a payment provider adapter can do. Providers genuinely differ (some
 * have no hosted checkout, some cannot change a plan in place), so callers
 * ask instead of assuming.
 */
enum ProviderCapability: string
{
    case Customers = 'CUSTOMERS';
    case Checkout = 'CHECKOUT';
    case Cancel = 'CANCEL';
    case ChangePlan = 'CHANGE_PLAN';
    case FetchSubscription = 'FETCH_SUBSCRIPTION';
    case Webhooks = 'WEBHOOKS';
}
