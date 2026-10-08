<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Enums\Billing\Feature;
use App\Http\Controllers\Controller;
use App\Http\Pagination\KeysetPaginator;
use App\Http\Requests\Billing\ListBillingUsageRequest;
use App\Http\Resources\Billing\BillingPlanResource;
use App\Http\Resources\Billing\BillingSubscriptionEventResource;
use App\Http\Resources\Billing\BillingSubscriptionResource;
use App\Http\Resources\Billing\BillingUsageEventResource;
use App\Http\Resources\CursorCollection;
use App\Http\Resources\PaginatedCollection;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionEvent;
use App\Models\BillingUsageEvent;
use App\Models\User;
use App\Services\Billing\BillingContextResolver;
use App\Services\Billing\Entitlements;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only billing endpoints (Phase 23, docs/api/README.md#billing). Always
 * the caller's own billing: no user, plan or subscription ID is accepted,
 * and nothing here changes a plan or a quota.
 */
final class BillingController extends Controller
{
    public function __construct(
        private readonly BillingContextResolver $contexts,
        private readonly Entitlements $entitlements,
        private readonly QuotaService $quotas,
    ) {}

    /** GET /api/v1/billing: the effective plan, entitlements and quotas. */
    public function overview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->contexts->resolve($user);
        $plan = $context->plan;

        return new JsonResponse(['data' => [
            'type' => 'billing_overview',
            'plan' => ['key' => $plan->key, 'version' => $plan->version, 'name' => $plan->name],
            'source' => $context->isFreeFallback() ? 'FREE_FALLBACK' : 'SUBSCRIPTION',
            'status' => $context->subscription?->status->value ?? 'FREE',
            'subscription' => $context->subscription === null ? null : (new BillingSubscriptionResource($context->subscription))->resolve($request),
            'inactive_subscription' => $context->lapsed === null ? null : (new BillingSubscriptionResource($context->lapsed->loadMissing('plan')))->resolve($request),
            'period' => ['start' => $context->periodStart->toIso8601ZuluString(), 'end' => $context->periodEnd->toIso8601ZuluString()],
            'entitlements' => array_map(fn (Feature $f): array => [
                'feature' => $f->value, 'label' => $f->label(), 'included' => $this->entitlements->allows($context, $f),
            ], Feature::cases()),
            'quotas' => array_map(fn (array $q): array => [
                'key' => $q['key']->value, 'label' => $q['key']->label(), 'unit' => $q['key']->unit(), 'period' => $q['key']->period()->value,
                'limit' => $q['limit'], 'used' => $q['used'], 'remaining' => $q['remaining'], 'unlimited' => $q['limit'] === null,
                'resets_at' => $q['resets_at'],
            ], $this->quotas->summary($context)),
        ]]);
    }

    /** GET /api/v1/billing/plans: the plan catalog. */
    public function plans(Request $request, PlanCatalog $catalog): JsonResponse
    {
        return new JsonResponse(['data' => array_map(fn ($plan): array => (new BillingPlanResource($plan))->resolve($request), $catalog->listed())]);
    }

    /** GET /api/v1/billing/usage: the caller's usage ledger, newest first. */
    public function usage(ListBillingUsageRequest $request, KeysetPaginator $keyset): PaginatedCollection|CursorCollection
    {
        /** @var User $user */
        $user = $request->user();
        if ($request->usesCursor()) {
            return new CursorCollection($keyset->paginate(
                BillingUsageEvent::query()->where('user_id', $user->getKey()), ['billing_usage_events.created_at', 'billing_usage_events.id'],
                fn (BillingUsageEvent $event): array => [(string) $event->getRawOriginal('created_at'), $event->id],
                'billing-usage:'.$user->getKey(), $request->cursor(), $request->perPage(), indexPrefix: 1,
            ), BillingUsageEventResource::class);
        }
        $events = BillingUsageEvent::query()->where('user_id', $user->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($events, BillingUsageEventResource::class);
    }

    /** GET /api/v1/billing/subscription: the current subscription and the history. */
    public function subscription(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = BillingSubscription::query()->where('user_id', $user->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->with('plan')->first();
        $history = BillingSubscriptionEvent::query()->where('user_id', $user->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->with('toPlan')->limit(50)->get();

        return new JsonResponse(['data' => [
            'type' => 'billing_subscription_state',
            'current' => $current === null ? null : (new BillingSubscriptionResource($current))->resolve($request),
            'history' => $history->map(fn (BillingSubscriptionEvent $e): array => (new BillingSubscriptionEventResource($e))->resolve($request))->all(),
        ]]);
    }
}
