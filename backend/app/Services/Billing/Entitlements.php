<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\Feature;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The only place feature access is decided. Callers ask for a feature,
 * never for a plan name.
 */
final readonly class Entitlements
{
    public function __construct(private BillingContextResolver $contexts) {}

    public function allows(BillingContext $context, Feature $feature): bool
    {
        return $context->plan->includes($feature);
    }

    /**
     * The subject's context (a user, an organization, or a project's
     * subject), if it includes the feature.
     *
     * @throws ApiException FEATURE_NOT_INCLUDED, or SUBSCRIPTION_INACTIVE when
     *                      the user's own subscription would include it but grants nothing now
     */
    public function require(User|string|Project|Organization $subject, Feature $feature): BillingContext
    {
        $context = $this->contexts->resolveSubject($subject);
        if ($this->allows($context, $feature)) {
            return $context;
        }

        $inactive = $context->lapsed !== null && $context->lapsed->plan->includes($feature);
        $code = $inactive ? ErrorCode::SubscriptionInactive : ErrorCode::FeatureNotIncluded;
        Log::info('billing.feature.denied', [...$context->subject(), 'feature' => $feature->value, 'plan' => $context->plan->key, 'error_code' => $code->value]);

        throw new ApiException($code, null, ['feature' => $feature->value, 'plan' => $context->plan->key]);
    }
}
