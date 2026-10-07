<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Actions\Billing\ProcessBillingWebhook;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/billing/webhooks/{provider}: payment provider events
 * (Phase 23, docs/billing/billing-architecture.md#webhooks). Not
 * session-authenticated: the provider's signature is the authentication.
 */
final class BillingWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ProcessBillingWebhook $process): JsonResponse
    {
        $limit = (int) config('codedna.billing.webhook_max_bytes');
        $declared = $request->headers->get('Content-Length');
        if (($declared !== null && ctype_digit($declared) && (int) $declared > $limit)) {
            throw new ApiException(ErrorCode::PayloadTooLarge);
        }
        $payload = $request->getContent();
        if (strlen($payload) > $limit) {
            throw new ApiException(ErrorCode::PayloadTooLarge);
        }
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        $result = $process->handle($provider, $payload, $headers);

        return new JsonResponse(['data' => [
            'type' => 'billing_webhook_receipt',
            'status' => $result['status'],
            'outcome' => $result['outcome']->value,
        ]]);
    }
}
