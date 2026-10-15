<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Challenge;

use App\Http\Controllers\Controller;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/challenges/status — whether coding challenges are offered on
 * this server, for any signed-in user, so the UI never advertises a disabled
 * feature (V1.0.0 ships with challenges off). `enabled` is the server
 * setting; `evaluation_available` adds a fresh, attested evaluator heartbeat.
 * Never the evaluator name, isolation level or any path.
 */
final class ChallengeStatusController extends Controller
{
    public function __invoke(ChallengeEvaluator $evaluator): JsonResponse
    {
        $enabled = config('codedna.challenges.enabled') === true;

        return response()->json(['data' => [
            'enabled' => $enabled,
            'evaluation_available' => $enabled && $evaluator->available(),
        ]]);
    }
}
