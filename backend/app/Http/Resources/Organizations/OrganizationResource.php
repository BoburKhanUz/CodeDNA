<?php

declare(strict_types=1);

namespace App\Http\Resources\Organizations;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization as its member sees it: the caller's own role and
 * membership status, and counts. The owner is identified only by the OWNER
 * membership, not by a user ID.
 *
 * Expects the query to provide `my_role`, `my_status`,
 * `active_members_count` and `active_projects_count`.
 *
 * @mixin Organization
 */
final class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'organization',
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'role' => $this->getAttribute('my_role'),
            'membership_status' => $this->getAttribute('my_status'),
            'member_count' => (int) $this->getAttribute('active_members_count'),
            'project_count' => (int) $this->getAttribute('active_projects_count'),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
