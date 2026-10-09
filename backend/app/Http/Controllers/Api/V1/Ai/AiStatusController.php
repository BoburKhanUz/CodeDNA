<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiStatus;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/ai/status — whether AI explanations can be requested right
 * now (Phase 29), for any signed-in user. A cached, cheap check (no
 * generation): enabled, provider kind, model, reachable, model available.
 * Never a URL, key, metric of other users or error body.
 */
final class AiStatusController extends Controller
{
    public function __invoke(AiStatus $status): JsonResponse
    {
        if (! $status->enabled()) {
            return response()->json(['data' => ['enabled' => false, 'available' => false, 'provider' => null, 'endpoint' => null, 'model' => null, 'reachable' => null, 'model_available' => null]]);
        }
        $health = $status->health();

        return response()->json(['data' => [
            'enabled' => true,
            'available' => $health->reachable && $health->modelAvailable !== false,
            'provider' => (string) config('codedna.ai.provider'),
            'endpoint' => $status->endpointKind(),
            'model' => (string) config('codedna.ai.model'),
            'reachable' => $health->reachable,
            'model_available' => $health->modelAvailable,
        ]]);
    }
}
