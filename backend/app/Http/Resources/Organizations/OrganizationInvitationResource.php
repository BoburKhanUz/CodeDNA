<?php

declare(strict_types=1);

namespace App\Http\Resources\Organizations;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * An invitation, for the organization's ADMINs and OWNER. Never contains
 * the token or its hash: the token is returned once, by the creating
 * request (as `token`, beside this resource).
 *
 * @mixin OrganizationInvitation
 */
final class OrganizationInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'organization_invitation',
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->statusAt(Carbon::now())->value,
            'invited_by' => ['id' => $this->invited_by_user_id, 'name' => $this->invitedBy->name],
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'accepted_at' => $this->accepted_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
