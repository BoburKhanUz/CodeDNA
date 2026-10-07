<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Enums\Billing\Feature;
use App\Enums\Billing\PlanStatus;
use App\Enums\Billing\QuotaKey;
use App\Models\BillingPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan of the catalog, as shown to users. Read-only: no endpoint accepts a plan.
 *
 * @mixin BillingPlan
 */
final class BillingPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'billing_plan',
            'key' => $this->key,
            'version' => $this->version,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'available' => $this->status === PlanStatus::Active,
            'currency' => $this->currency,
            'prices' => [
                'monthly_minor' => $this->monthly_price_minor,
                'annual_minor' => $this->annual_price_minor,
            ],
            'features' => array_map(fn (Feature $f): array => [
                'key' => $f->value, 'label' => $f->label(), 'included' => $this->includes($f),
            ], Feature::cases()),
            'quotas' => array_map(fn (QuotaKey $q): array => [
                'key' => $q->value, 'label' => $q->label(), 'unit' => $q->unit(), 'period' => $q->period()->value,
                'limit' => $this->includes($q->feature()) ? $this->limitFor($q) : 0,
            ], QuotaKey::cases()),
        ];
    }
}
