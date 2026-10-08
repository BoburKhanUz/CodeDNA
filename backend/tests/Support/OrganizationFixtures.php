<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Organizations for tests (Phase 24). Organizations are created through the
 * real CreateOrganization action (owner membership, billing account, audit
 * event); members are added directly, as an accepted invitation would add
 * them, so tests can start from any membership state.
 */
final class OrganizationFixtures
{
    public static function create(User $owner, string $name = 'Acme Engineering'): Organization
    {
        return app(CreateOrganization::class)->handle($owner, $name);
    }

    public static function member(Organization $organization, ?User $user = null, OrganizationRole $role = OrganizationRole::Member,
        MembershipStatus $status = MembershipStatus::Active): OrganizationMembership
    {
        $membership = new OrganizationMembership;
        $membership->forceFill([
            'organization_id' => $organization->getKey(),
            'user_id' => ($user ?? User::factory()->create())->getKey(),
            'role' => $role,
            'status' => $status,
            'joined_at' => Carbon::now(),
        ])->save();

        return $membership;
    }

    public static function membershipOf(Organization $organization, User $user): OrganizationMembership
    {
        return OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())->firstOrFail();
    }

    /** A team project created by $creator (no billing check: setup only). */
    public static function project(Organization $organization, User $creator, array $attributes = []): Project
    {
        $project = Project::factory()->for($creator)->make($attributes);
        $project->forceFill(['organization_id' => $organization->getKey()])->save();

        return $project->refresh();
    }

    /** Sets an organization's status directly (SUSPENDED has no API; tests need ARCHIVED quickly). */
    public static function setStatus(Organization $organization, string $status): Organization
    {
        DB::table('organizations')->where('id', $organization->getKey())->update(['status' => $status]);

        return $organization->refresh();
    }

    /**
     * Deletes the organizations of tests that commit their rows (concurrency
     * tests). Models refuse deletes; cleanup uses the query builder, in a
     * transaction so the deferred owner check sees the organization gone.
     *
     * @param  list<string>  $organizationIds
     */
    public static function forget(array $organizationIds): void
    {
        if ($organizationIds === []) {
            return;
        }
        DB::transaction(function () use ($organizationIds): void {
            $projects = DB::table('projects')->whereIn('organization_id', $organizationIds)->pluck('id');
            DB::table('billing_usage_events')->whereIn('organization_id', $organizationIds)->delete();
            DB::table('billing_organization_usage_counters')->whereIn('organization_id', $organizationIds)->delete();
            DB::table('analysis_runs')->whereIn('project_id', $projects)->delete();
            DB::table('source_snapshots')->whereIn('project_id', $projects)->delete();
            DB::table('projects')->whereIn('id', $projects)->delete();
            DB::table('organization_audit_events')->whereIn('organization_id', $organizationIds)->delete();
            DB::table('organization_invitations')->whereIn('organization_id', $organizationIds)->delete();
            DB::table('organization_billing_accounts')->whereIn('organization_id', $organizationIds)->delete();
            DB::table('organization_memberships')->whereIn('organization_id', $organizationIds)->delete();
            DB::table('organizations')->whereIn('id', $organizationIds)->delete();
        });
    }
}
