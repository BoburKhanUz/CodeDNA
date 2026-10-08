<?php

declare(strict_types=1);

namespace App\Http\Resources\Organizations;

use App\Models\OrganizationAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit log entry, for the organization's ADMINs and OWNER.
 *
 * @mixin OrganizationAuditEvent
 */
final class OrganizationAuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'organization_audit_event',
            'action' => $this->action->value,
            'actor' => $this->actor_user_id === null ? null : ['id' => $this->actor_user_id, 'name' => $this->actor?->name],
            'target' => $this->target_type === null ? null : ['type' => $this->target_type, 'id' => $this->target_id],
            'metadata' => (object) $this->metadata,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
