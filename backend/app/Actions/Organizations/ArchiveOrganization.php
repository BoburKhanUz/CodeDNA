<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ACTIVE -> ARCHIVED, by the OWNER only; irreversible. This is how an
 * organization is "deleted": nothing is removed. Projects, their history,
 * memberships and the audit log stay readable to members; no invitation,
 * membership, project or billing change is possible any more.
 */
final readonly class ArchiveOrganization
{
    public function __construct(private OrganizationAccess $access, private OrganizationAudit $audit) {}

    public function handle(Organization $organization, User $actor): Organization
    {
        $archived = DB::transaction(function () use ($organization, $actor): Organization {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $this->access->requireForChange($actor, $locked, OrganizationRole::Owner);
            $locked->forceFill(['status' => OrganizationStatus::Archived])->save();
            $this->audit->record($locked, $actor, OrganizationAuditAction::OrganizationArchived, $locked);

            return $locked;
        });
        Log::info('organization.archived', ['organization_id' => $archived->id, 'user_id' => $actor->id]);

        return $archived;
    }
}
