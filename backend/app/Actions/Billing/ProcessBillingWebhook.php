<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\WebhookOutcome;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\BillingWebhookEvent;
use App\Services\Billing\Provider\PaymentProviders;
use App\Services\Billing\Provider\WebhookRejected;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provider webhook ingestion (docs/billing/billing-architecture.md#webhooks).
 *
 * 1. Only the configured provider is accepted (others: 404).
 * 2. The signature (and its timestamp) is verified on the raw body before
 *    anything is parsed or stored.
 * 3. The provider event ID is claimed with an insert that ignores
 *    conflicts: a duplicate, even a concurrent one, finds the row taken and
 *    changes nothing.
 * 4. The event is applied by SubscriptionService, and the outcome recorded
 *    once. Only the payload's SHA-256 is kept, never the payload.
 */
final readonly class ProcessBillingWebhook
{
    public function __construct(private PaymentProviders $providers, private SubscriptionService $subscriptions) {}

    /**
     * @param  array<string, string>  $headers  lowercase name => value
     * @return array{status: 'processed'|'duplicate', outcome: WebhookOutcome}
     */
    public function handle(string $providerName, string $payload, array $headers): array
    {
        $provider = $this->providers->named($providerName) ?? throw new ApiException(ErrorCode::ResourceNotFound);
        if (! $provider->verifyWebhook($payload, $headers)) {
            Log::warning('billing.webhook.rejected', ['provider' => $providerName, 'reason' => 'signature']);

            throw new ApiException(ErrorCode::BadRequest, 'The webhook signature is invalid.');
        }
        try {
            $event = $provider->parseWebhook($payload);
        } catch (WebhookRejected $e) {
            Log::warning('billing.webhook.rejected', ['provider' => $providerName, 'reason' => $e->reason]);

            throw new ApiException(ErrorCode::BadRequest, 'The webhook body is not a supported event.');
        }

        return DB::transaction(function () use ($event, $payload): array {
            $id = strtolower((string) Str::ulid());
            $claimed = BillingWebhookEvent::query()->insertOrIgnore([
                'id' => $id, 'provider' => $event->provider, 'provider_event_id' => $event->eventId,
                'event_type' => $event->type->value, 'occurred_at' => $event->occurredAt,
                'provider_customer_ref' => $event->customerRef, 'provider_subscription_ref' => $event->subscriptionRef,
                'plan_key' => $event->planKey, 'plan_version' => $event->planVersion,
                'period_start' => $event->periodStart, 'period_end' => $event->periodEnd,
                'trial_ends_at' => $event->trialEndsAt, 'at_period_end' => $event->atPeriodEnd,
                'payload_sha256' => hash('sha256', $payload), 'outcome' => WebhookOutcome::Received->value,
                'received_at' => Carbon::now(),
            ]);
            if ($claimed === 0) {
                $previous = BillingWebhookEvent::query()->where('provider', $event->provider)->where('provider_event_id', $event->eventId)->firstOrFail();
                Log::info('billing.webhook.duplicate', ['provider' => $event->provider, 'provider_event_id' => $event->eventId, 'webhook_event_id' => $previous->id]);

                return ['status' => 'duplicate', 'outcome' => $previous->outcome];
            }
            Log::info('billing.webhook.received', ['provider' => $event->provider, 'provider_event_id' => $event->eventId, 'type' => $event->type->value, 'webhook_event_id' => $id]);

            [$outcome, $subscription] = $this->subscriptions->apply($event, $id);
            BillingWebhookEvent::query()->findOrFail($id)->forceFill([
                'outcome' => $outcome, 'billing_subscription_id' => $subscription?->id,
                'processed_at' => $outcome === WebhookOutcome::Deferred ? null : Carbon::now(),
            ])->save();
            if ($outcome !== WebhookOutcome::Applied) {
                Log::warning('billing.webhook.not_applied', ['provider' => $event->provider, 'webhook_event_id' => $id, 'outcome' => $outcome->value]);
            }

            return ['status' => 'processed', 'outcome' => $outcome];
        });
    }
}
