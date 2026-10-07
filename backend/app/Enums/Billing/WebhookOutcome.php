<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * What became of a verified provider event (one row per provider event ID).
 * RECEIVED exists only inside the processing transaction; DEFERRED until the
 * subscription's activation arrives.
 */
enum WebhookOutcome: string
{
    case Received = 'RECEIVED';
    case Applied = 'APPLIED';
    // Older than the last event applied to the subscription: never reverts it.
    case Stale = 'STALE';
    // Not allowed from the subscription's current state (for example any event after a terminal state).
    case InvalidTransition = 'INVALID_TRANSITION';
    // For a subscription not activated yet (delivered out of order): kept, and
    // replayed in provider order once the activation arrives.
    case Deferred = 'DEFERRED';
    case UnknownCustomer = 'UNKNOWN_CUSTOMER';
    case UnknownSubscription = 'UNKNOWN_SUBSCRIPTION';
    case UnknownPlan = 'UNKNOWN_PLAN';
    // An activation while the user already has another current subscription.
    case Conflict = 'CONFLICT';
}
