<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Enterprise\EnterpriseEdition;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/installation (Phase 27): what this installation offers, so the
 * interface can explain it. Informational only: every entitlement is decided
 * on the server where it is used, never from this response.
 *
 * The license block carries the verified claims a member may see (licensee,
 * validity, the organization plan and seat entitlement); never the license
 * document, its signature, its key, the installation host or a file path.
 */
final class InstallationController extends Controller
{
    public function __invoke(EnterpriseEdition $edition, Repository $config): JsonResponse
    {
        $verification = $edition->verification();
        $license = $verification->license;

        return new JsonResponse(['data' => [
            'type' => 'installation',
            'edition' => $edition->isEnterprise() ? 'ENTERPRISE' : 'COMMUNITY',
            'license' => [
                'status' => $verification->status->value,
                'licensee' => $license?->licensee,
                'expires_at' => $license?->expiresAt->toIso8601ZuluString(),
                'organization_plan' => $verification->granting()?->organizationPlan,
                'organization_seats' => $verification->granting()?->organizationSeats,
            ],
            'registration' => ['mode' => (string) $config->get('codedna.registration.mode')],
        ]]);
    }
}
