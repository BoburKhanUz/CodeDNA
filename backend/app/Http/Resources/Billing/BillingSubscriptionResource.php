<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\BillingSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A subscription as its owner sees it. Provider customer and subscription
 * references are never returned.
 *
 * @mixin BillingSubscription
 */
final class BillingSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $iso = static fn (?Carbon $t): ?string => $t?->toIso8601ZuluString();

        return [
            'id' => $this->id,
            'type' => 'billing_subscription',
            'status' => $this->status->value,
            'provider' => $this->provider,
            'plan' => ['key' => $this->plan->key, 'version' => $this->plan->version, 'name' => $this->plan->name],
            'grants_access' => $this->grantsAt(Carbon::now()),
            'started_at' => $iso($this->started_at),
            'current_period_start' => $iso($this->current_period_start),
            'current_period_end' => $iso($this->current_period_end),
            'trial_ends_at' => $iso($this->trial_ends_at),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'canceled_at' => $iso($this->canceled_at),
            'ended_at' => $iso($this->ended_at),
        ];
    }
}
