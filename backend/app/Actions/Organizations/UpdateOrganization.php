<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Support\Facades\DB;

/**
 * Renames an organization (ADMIN or OWNER, ACTIVE organization). The slug
 * and owner never change.
 */
final readonly class UpdateOrganization
{
    public function __construct(private OrganizationAccess $access, private OrganizationAudit $audit) {}

    public function handle(Organization $organization, User $actor, string $name): Organization
    {
        return DB::transaction(function () use ($organization, $actor, $name): Organization {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $this->access->requireForChange($actor, $locked, OrganizationRole::Admin);
            if ($locked->name !== $name) {
                $previous = $locked->name;
                $locked->forceFill(['name' => $name])->save();
                $this->audit->record($locked, $actor, OrganizationAuditAction::OrganizationUpdated, $locked, ['name' => ['from' => $previous, 'to' => $name]]);
            }

            return $locked;
        });
    }
}
