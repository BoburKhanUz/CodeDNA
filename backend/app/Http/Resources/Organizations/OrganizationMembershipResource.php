<?php

declare(strict_types=1);

namespace App\Http\Resources\Organizations;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member of an organization. The email is shown only to ADMINs and the
 * OWNER (who manage invitations); every member sees names and roles.
 *
 * @mixin OrganizationMembership
 */
final class OrganizationMembershipResource extends JsonResource
{
    public function __construct(OrganizationMembership $resource, private readonly bool $withEmail = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'organization_membership',
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user->name,
                'email' => $this->withEmail ? $this->user->email : null,
            ],
            'role' => $this->role->value,
            'status' => $this->status->value,
            'joined_at' => $this->joined_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
