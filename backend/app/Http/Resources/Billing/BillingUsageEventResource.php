<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\BillingUsageEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One usage ledger entry of the caller: what was consumed, refunded or refused.
 *
 * @mixin BillingUsageEvent
 */
final class BillingUsageEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'billing_usage_event',
            'quota' => $this->quota_key->value,
            'outcome' => $this->outcome->value,
            'amount' => $this->amount,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'period_start' => $this->period_start->toIso8601ZuluString(),
            'period_end' => $this->period_end->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
