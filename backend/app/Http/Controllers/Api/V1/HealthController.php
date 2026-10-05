<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SystemHealth;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health — readiness of the API and its hard dependencies.
 * 200 when every check passes, 503 otherwise (same body shape).
 */
final class HealthController extends Controller
{
    public function __invoke(SystemHealth $health, Config $config): JsonResponse
    {
        $checks = $health->check();
        $healthy = ! in_array(false, $checks, true);

        return new JsonResponse([
            'data' => [
                'status' => $healthy ? 'ok' : 'fail',
                'service' => $config->get('codedna.service'),
                'version' => $config->get('codedna.version'),
                'api_version' => $config->get('codedna.api_version'),
                'checks' => array_map(static fn (bool $ok): string => $ok ? 'ok' : 'fail', $checks),
            ],
        ], $healthy ? 200 : 503);
    }
}
