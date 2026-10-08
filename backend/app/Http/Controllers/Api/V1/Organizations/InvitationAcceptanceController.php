<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\AcceptInvitation;
use App\Actions\Organizations\PreviewInvitation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Organizations\OrganizationMembershipResource;
use App\Http\Resources\Organizations\OrganizationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/organizations/invitations/{token} (docs/teams/invitations.md).
 * The preview needs no session and shows only what the link's holder may
 * see; acceptance needs the invited user's session. The token is in the
 * path, so the access log redacts it (docker/nginx/conf.d/default.conf).
 */
final class InvitationAcceptanceController extends Controller
{
    public function show(string $token, PreviewInvitation $preview): JsonResponse
    {
        return new JsonResponse(['data' => ['type' => 'organization_invitation_preview', ...$preview->handle($token)]]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $accept->handle($user, $token);
        $organization = OrganizationQueries::forMember((string) $user->getKey())->whereKey($membership->organization_id)->firstOrFail();

        return new JsonResponse(['data' => [
            'type' => 'organization_invitation_acceptance',
            'organization' => (new OrganizationResource($organization))->resolve($request),
            'membership' => (new OrganizationMembershipResource($membership->load('user:id,name,email')))->resolve($request),
        ]]);
    }
}
