<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24: members, roles and the owner's protection
 * (docs/teams/authorization.md#membership-changes).
 */
final class OrganizationMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = OrganizationFixtures::create($this->owner);
    }

    private function url(?OrganizationMembership $membership = null): string
    {
        return "/api/v1/organizations/{$this->organization->id}/members".($membership === null ? '' : "/{$membership->id}");
    }

    public function test_members_see_names_and_roles_and_only_admins_see_emails(): void
    {
        $admin = OrganizationFixtures::member($this->organization, role: OrganizationRole::Admin);
        $member = OrganizationFixtures::member($this->organization);
        OrganizationFixtures::member($this->organization, status: MembershipStatus::Removed);
        OrganizationFixtures::member($this->organization, status: MembershipStatus::Suspended);

        $asMember = $this->asUser($member->user)->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 4)->json('data');
        $asAdmin = $this->asUser($admin->user)->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(['OWNER', 'ADMIN', 'MEMBER', 'MEMBER'], array_column($asMember, 'role'));
        $this->assertSame([null], array_values(array_unique(array_map(fn ($m) => $m['user']['email'], $asMember))));
        $this->assertSame($this->owner->email, $asAdmin[0]['user']['email']);
        $this->assertNotContains('REMOVED', array_column($asAdmin, 'status'));
    }

    public function test_the_owner_promotes_and_demotes_and_each_change_is_audited(): void
    {
        $member = OrganizationFixtures::member($this->organization);

        $this->asUser($this->owner)->patchJson($this->url($member), ['role' => 'ADMIN'])->assertOk()->assertJsonPath('data.role', 'ADMIN');
        $this->asUser($this->owner)->patchJson($this->url($member), ['role' => 'MEMBER'])->assertOk()->assertJsonPath('data.role', 'MEMBER');

        $changes = OrganizationAuditEvent::query()->where('action', 'MEMBER_ROLE_CHANGED')->orderBy('created_at')->orderBy('id')->get();
        $this->assertEquals([['from' => 'MEMBER', 'to' => 'ADMIN'], ['from' => 'ADMIN', 'to' => 'MEMBER']], $changes->pluck('metadata.role')->all());
        $this->assertSame([$member->id], $changes->pluck('target_id')->unique()->values()->all());
    }

    public function test_nobody_can_assign_owner_or_rise_above_their_own_role(): void
    {
        $admin = OrganizationFixtures::member($this->organization, role: OrganizationRole::Admin);
        $member = OrganizationFixtures::member($this->organization);
        $other = OrganizationFixtures::member($this->organization);

        // OWNER is never assignable.
        $this->asUser($this->owner)->patchJson($this->url($member), ['role' => 'OWNER'])->assertUnprocessable();
        // A member changes nobody, not even themselves.
        $this->asUser($member->user)->patchJson($this->url($member), ['role' => 'ADMIN'])->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser($member->user)->patchJson($this->url($other), ['status' => 'SUSPENDED'])->assertForbidden();
        // An admin acts on members only: not on another admin, not on themselves.
        $peer = OrganizationFixtures::member($this->organization, role: OrganizationRole::Admin);
        $this->asUser($admin->user)->patchJson($this->url($peer), ['role' => 'MEMBER'])->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser($admin->user)->patchJson($this->url($admin), ['status' => 'SUSPENDED'])->assertForbidden();
        // ...and may raise a member up to their own role.
        $this->asUser($admin->user)->patchJson($this->url($member), ['role' => 'ADMIN'])->assertOk();
        // Client-sent identity is never trusted.
        $this->asUser($other->user)->patchJson($this->url($other), ['role' => 'ADMIN', 'actor_role' => 'OWNER', 'user_id' => $this->owner->id])->assertForbidden();

        $this->assertSame([OrganizationRole::Admin, OrganizationRole::Admin, OrganizationRole::Member], [$peer->refresh()->role, $member->refresh()->role, $other->refresh()->role]);
    }

    public function test_the_owner_is_never_demoted_suspended_or_removed(): void
    {
        $ownerMembership = OrganizationFixtures::membershipOf($this->organization, $this->owner);
        $admin = OrganizationFixtures::member($this->organization, role: OrganizationRole::Admin);

        foreach ([$this->owner, $admin->user] as $actor) {
            $this->asUser($actor)->patchJson($this->url($ownerMembership), ['role' => 'MEMBER'])->assertStatus(409)->assertJsonPath('error.code', 'CANNOT_CHANGE_OWNER_ROLE');
            $this->asUser($actor)->patchJson($this->url($ownerMembership), ['status' => 'SUSPENDED'])->assertStatus(409)->assertJsonPath('error.code', 'CANNOT_CHANGE_OWNER_ROLE');
            $this->asUser($actor)->deleteJson($this->url($ownerMembership))->assertStatus(409)->assertJsonPath('error.code', 'CANNOT_REMOVE_OWNER');
        }
        $this->assertSame([OrganizationRole::Owner, MembershipStatus::Active], [$ownerMembership->refresh()->role, $ownerMembership->status]);
    }

    public function test_suspension_ends_access_and_reactivation_needs_a_seat(): void
    {
        $member = OrganizationFixtures::member($this->organization);

        $this->asUser($this->owner)->patchJson($this->url($member), ['status' => 'SUSPENDED'])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->asUser($member->user)->getJson("/api/v1/organizations/{$this->organization->id}")->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_SUSPENDED');

        // Fill every seat (5): the owner and four others.
        foreach (range(1, 4) as $i) {
            OrganizationFixtures::member($this->organization);
        }
        $this->asUser($this->owner)->patchJson($this->url($member), ['status' => 'ACTIVE'])->assertStatus(402)
            ->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED')->assertJsonPath('error.details', ['limit' => 5, 'used' => 5]);
        $this->assertSame(MembershipStatus::Suspended, $member->refresh()->status);

        OrganizationFixtures::membershipOf($this->organization, User::query()->whereKey(OrganizationMembership::query()->where('role', 'MEMBER')
            ->where('status', 'ACTIVE')->value('user_id'))->firstOrFail())->forceFill(['status' => MembershipStatus::Removed])->save();
        $this->asUser($this->owner)->patchJson($this->url($member), ['status' => 'ACTIVE'])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertSame(['MEMBER_SUSPENDED', 'MEMBER_REACTIVATED'], OrganizationAuditEvent::query()->whereIn('action', ['MEMBER_SUSPENDED', 'MEMBER_REACTIVATED'])
            ->orderBy('created_at')->orderBy('id')->pluck('action')->map->value->all());
    }

    public function test_removal_is_a_status_that_ends_access_immediately(): void
    {
        $member = OrganizationFixtures::member($this->organization);
        $this->asUser($member->user)->getJson("/api/v1/organizations/{$this->organization->id}")->assertOk();

        $this->asUser($this->owner)->deleteJson($this->url($member))->assertOk()->assertJsonPath('data.status', 'REMOVED');

        $this->asUser($member->user)->getJson("/api/v1/organizations/{$this->organization->id}")->assertNotFound();
        $this->assertSame(MembershipStatus::Removed, OrganizationMembership::query()->findOrFail($member->id)->status, 'the record is kept');
        // A removed membership is gone for this API; the person returns only through an invitation.
        $this->asUser($this->owner)->patchJson($this->url($member), ['status' => 'ACTIVE'])->assertNotFound();
        $this->asUser($this->owner)->deleteJson($this->url($member))->assertNotFound();
        $this->assertSame(1, OrganizationAuditEvent::query()->where('action', 'MEMBER_REMOVED')->where('target_id', $member->id)->count());
    }

    public function test_an_admin_cannot_remove_another_admin(): void
    {
        $admin = OrganizationFixtures::member($this->organization, role: OrganizationRole::Admin);
        $peer = OrganizationFixtures::member($this->organization, role: OrganizationRole::Admin);

        $this->asUser($admin->user)->deleteJson($this->url($peer))->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser($admin->user)->deleteJson($this->url($admin))->assertForbidden();
        $this->assertSame([MembershipStatus::Active, MembershipStatus::Active], [$peer->refresh()->status, $admin->refresh()->status]);
        $this->asUser($this->owner)->deleteJson($this->url($peer))->assertOk();
    }

    public function test_memberships_of_another_organization_cannot_be_reached_through_this_one(): void
    {
        $otherOrganization = OrganizationFixtures::create(User::factory()->create(), 'Other');
        $foreign = OrganizationFixtures::member($otherOrganization);

        $this->asUser($this->owner)->patchJson($this->url($foreign), ['role' => 'ADMIN'])->assertNotFound();
        $this->asUser($this->owner)->deleteJson($this->url($foreign))->assertNotFound();
        $this->assertSame([OrganizationRole::Member, MembershipStatus::Active], [$foreign->refresh()->role, $foreign->status]);
    }

    public function test_the_input_must_name_a_role_or_a_status(): void
    {
        $member = OrganizationFixtures::member($this->organization);
        foreach ([[], ['status' => 'REMOVED'], ['role' => 'owner'], ['status' => 'PENDING']] as $body) {
            $this->asUser($this->owner)->patchJson($this->url($member), $body)->assertUnprocessable();
        }
    }
}
