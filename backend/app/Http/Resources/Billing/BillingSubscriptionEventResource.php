<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\BillingSubscriptionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the subscription history.
 *
 * @mixin BillingSubscriptionEvent
 */
final class BillingSubscriptionEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'billing_subscription_event',
            'event' => $this->type->value,
            'subscription_id' => $this->billing_subscription_id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'plan' => ['key' => $this->toPlan->key, 'version' => $this->toPlan->version],
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
        ];
    }
}
