<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\ProjectStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * The organizations a user belongs to, with the user's own role and status
 * and the active member and project counts, in one query (no N+1).
 * REMOVED memberships are not listed.
 */
final class OrganizationQueries
{
    /**
     * @return Builder<Organization>
     */
    public static function forMember(string $userId): Builder
    {
        return Organization::query()
            ->join('organization_memberships as mine', function ($join) use ($userId): void {
                $join->on('mine.organization_id', '=', 'organizations.id')->where('mine.user_id', '=', $userId);
            })
            ->whereIn('mine.status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value])
            ->select('organizations.*', 'mine.role as my_role', 'mine.status as my_status')
            ->withCount([
                'memberships as active_members_count' => fn ($query) => $query->where('status', MembershipStatus::Active->value),
                'projects as active_projects_count' => fn ($query) => $query->where('status', ProjectStatus::Active->value),
            ]);
    }
}
