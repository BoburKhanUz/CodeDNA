<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\QuotaKey;
use App\Enums\Billing\QuotaPeriod;
use App\Enums\Billing\UsageOutcome;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\BillingUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The usage ledger (docs/billing/entitlements-and-quotas.md#usage-accounting).
 *
 * - consume(): inside the transaction that creates the resource. Locks the
 *   period counter row, so concurrent requests take the last unit one at a
 *   time; the loser gets QUOTA_EXCEEDED and nothing is created. Charging is
 *   idempotent by resource: a retry for the same resource is never charged
 *   twice.
 * - refund(): once per charged resource, when its operation ends without a
 *   result (FAILED analysis or assessment, ERROR evaluation, FAILED import).
 * - Refusals are recorded as REJECTED (after the caller's transaction rolls
 *   back, so the refusal itself is never lost with it).
 */
final readonly class UsageService
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @throws ApiException FEATURE_NOT_INCLUDED, SUBSCRIPTION_INACTIVE, QUOTA_EXCEEDED
     */
    public function consume(User|string $user, QuotaKey $key, string $resourceType, string $resourceId, int $amount = 1): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('consume() must run inside the transaction that creates the resource.');
        }
        if ($amount < 1) {
            throw new LogicException('Usage is consumed in positive whole units.');
        }
        $context = $this->entitlements->require($user, $key->feature());
        if ($this->charged($key, $resourceType, $resourceId)) {
            return;
        }

        $limit = $context->plan->includes($key->feature()) ? $context->plan->limitFor($key) : 0;
        DB::table('billing_usage_counters')->insertOrIgnore([
            'user_id' => $context->userId, 'quota_key' => $key->value, 'period_start' => $context->periodStart, 'used' => 0, 'updated_at' => $context->now,
        ]);
        $counter = DB::table('billing_usage_counters')
            ->where('user_id', $context->userId)->where('quota_key', $key->value)->where('period_start', $context->periodStart)
            ->lockForUpdate()->first();
        $used = (int) ($counter->used ?? 0);
        if ($limit !== null && $used + $amount > $limit) {
            $this->refuse($context, $key, $amount, $limit, $used);
        }

        DB::table('billing_usage_counters')
            ->where('user_id', $context->userId)->where('quota_key', $key->value)->where('period_start', $context->periodStart)
            ->update(['used' => DB::raw('used + '.$amount), 'updated_at' => $context->now]);
        $this->record($context, $key, UsageOutcome::Accepted, $amount, $resourceType, $resourceId);
    }

    /**
     * A cheap check before expensive work (storing an archive): refuses now if
     * the amount no longer fits. Not a reservation; consume() decides.
     *
     * @throws ApiException FEATURE_NOT_INCLUDED, SUBSCRIPTION_INACTIVE, QUOTA_EXCEEDED
     */
    public function ensureAvailable(User|string $user, QuotaKey $key, int $amount = 1): void
    {
        $context = $this->entitlements->require($user, $key->feature());
        $limit = $context->plan->includes($key->feature()) ? $context->plan->limitFor($key) : 0;
        $used = (int) DB::table('billing_usage_counters')
            ->where('user_id', $context->userId)->where('quota_key', $key->value)->where('period_start', $context->periodStart)
            ->value('used');
        if ($limit !== null && $used + $amount > $limit) {
            $this->refuse($context, $key, $amount, $limit, $used);
        }
    }

    /**
     * Returns a resource's charge to its period. Idempotent; a resource that
     * was never charged is ignored.
     */
    public function refund(QuotaKey $key, string $resourceType, string $resourceId): void
    {
        DB::transaction(function () use ($key, $resourceType, $resourceId): void {
            $charge = BillingUsageEvent::query()
                ->where('quota_key', $key->value)->where('resource_type', $resourceType)->where('resource_id', $resourceId)
                ->where('outcome', UsageOutcome::Accepted->value)->first();
            if ($charge === null) {
                return;
            }
            $counter = DB::table('billing_usage_counters')
                ->where('user_id', $charge->user_id)->where('quota_key', $key->value)->where('period_start', $charge->period_start)
                ->lockForUpdate()->first();
            $refunded = BillingUsageEvent::query()
                ->where('quota_key', $key->value)->where('resource_type', $resourceType)->where('resource_id', $resourceId)
                ->where('outcome', UsageOutcome::Refunded->value)->exists();
            if ($refunded || $counter === null) {
                return;
            }
            DB::table('billing_usage_counters')
                ->where('user_id', $charge->user_id)->where('quota_key', $key->value)->where('period_start', $charge->period_start)
                ->update(['used' => DB::raw('GREATEST(used - '.$charge->amount.', 0)'), 'updated_at' => now()]);
            $refund = new BillingUsageEvent;
            $refund->forceFill([
                'user_id' => $charge->user_id, 'billing_subscription_id' => $charge->billing_subscription_id, 'billing_plan_id' => $charge->billing_plan_id,
                'quota_key' => $key, 'outcome' => UsageOutcome::Refunded, 'amount' => -$charge->amount,
                'period_start' => $charge->period_start, 'period_end' => $charge->period_end,
                'resource_type' => $resourceType, 'resource_id' => $resourceId,
            ])->save();
            Log::info('billing.usage.refunded', ['user_id' => $charge->user_id, 'quota' => $key->value, 'resource_type' => $resourceType, 'resource_id' => $resourceId]);
        });
    }

    /**
     * Refuses a request over its quota: QUOTA_EXCEEDED now, and a REJECTED
     * ledger entry once the caller's transaction has rolled back.
     *
     * @throws ApiException QUOTA_EXCEEDED, always
     */
    public function refuse(BillingContext $context, QuotaKey $key, int $amount, int $limit, int $used): never
    {
        $reject = fn () => $this->record($context, $key, UsageOutcome::Rejected, $amount, null, null);
        DB::transactionLevel() === 0 ? $reject() : DB::afterRollBack($reject);
        Log::info('billing.quota.exceeded', ['user_id' => $context->userId, 'quota' => $key->value, 'plan' => $context->plan->key, 'limit' => $limit, 'used' => $used]);

        throw new ApiException(ErrorCode::QuotaExceeded, null, [
            'quota' => $key->value,
            'limit' => $limit,
            'used' => $used,
            'resets_at' => $key->period() === QuotaPeriod::Monthly ? $context->periodEnd->toIso8601ZuluString() : null,
        ]);
    }

    private function charged(QuotaKey $key, string $resourceType, string $resourceId): bool
    {
        return BillingUsageEvent::query()
            ->where('quota_key', $key->value)->where('resource_type', $resourceType)->where('resource_id', $resourceId)
            ->where('outcome', UsageOutcome::Accepted->value)->exists();
    }

    private function record(BillingContext $context, QuotaKey $key, UsageOutcome $outcome, int $amount, ?string $resourceType, ?string $resourceId): void
    {
        $event = new BillingUsageEvent;
        $event->forceFill([
            'user_id' => $context->userId, 'billing_subscription_id' => $context->subscription?->id, 'billing_plan_id' => $context->plan->id,
            'quota_key' => $key, 'outcome' => $outcome, 'amount' => $amount,
            'period_start' => $context->periodStart, 'period_end' => $context->periodEnd,
            'resource_type' => $resourceType, 'resource_id' => $resourceId,
        ])->save();
    }
}
