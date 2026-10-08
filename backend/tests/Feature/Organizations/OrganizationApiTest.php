<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationBillingAccount;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24: organizations, their creation and the owner invariant
 * (docs/teams/teams-architecture.md).
 */
final class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/organizations';

    public function test_creating_an_organization_makes_the_creator_its_owner_in_one_transaction(): void
    {
        $user = User::factory()->create();

        $response = $this->asUser($user)->postJson(self::URL, ['name' => 'Acme Engineering'])->assertCreated();

        $organization = Organization::query()->sole();
        $response->assertJsonPath('data.id', $organization->id)->assertJsonPath('data.role', 'OWNER')
            ->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.member_count', 1)->assertJsonPath('data.project_count', 0);
        $this->assertSame($user->id, $organization->owner_user_id);
        $membership = OrganizationMembership::query()->sole();
        $this->assertSame([OrganizationRole::Owner, MembershipStatus::Active, $user->id], [$membership->role, $membership->status, $membership->user_id]);
        $account = OrganizationBillingAccount::query()->sole();
        $this->assertSame(['FREE', '1.0.0', 5], [$account->plan->key, $account->entitlement_version, $account->seat_limit]);
        $this->assertSame(['ORGANIZATION_CREATED'], OrganizationAuditEvent::query()->pluck('action')->map->value->all());
    }

    public function test_the_slug_is_generated_and_nothing_but_the_name_is_taken_from_input(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $data = $this->asUser($user)->postJson(self::URL, ['name' => 'Ünïcode Team!', 'slug' => 'chosen', 'status' => 'ARCHIVED',
            'owner_user_id' => $other->id, 'seat_limit' => 999, 'plan' => 'PRO'])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^unicode-team-[a-z0-9]{6}$/', $data['slug']);
        $this->assertSame(['ACTIVE', 'OWNER'], [$data['status'], $data['role']]);
        $this->assertSame($user->id, Organization::query()->sole()->owner_user_id);
        $this->assertSame(5, OrganizationBillingAccount::query()->sole()->seat_limit);
        // Two organizations with the same name get different slugs; nothing reveals that the name is taken.
        $this->asUser($other)->postJson(self::URL, ['name' => 'Ünïcode Team!'])->assertCreated();
        $this->assertSame(2, Organization::query()->distinct()->count('slug'));
    }

    public function test_names_are_validated(): void
    {
        $user = User::factory()->create();
        // Surrounding whitespace is trimmed by the TrimStrings middleware before validation.
        foreach (['', '   ', str_repeat('n', 101), ['array']] as $name) {
            $this->asUser($user)->postJson(self::URL, ['name' => $name])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertSame(0, Organization::query()->count());
    }

    public function test_a_user_lists_only_the_organizations_they_belong_to(): void
    {
        $user = User::factory()->create();
        $mine = OrganizationFixtures::create($user, 'Alpha');
        $joined = OrganizationFixtures::create(User::factory()->create(), 'Beta');
        OrganizationFixtures::member($joined, $user, OrganizationRole::Admin);
        $left = OrganizationFixtures::create(User::factory()->create(), 'Gamma');
        OrganizationFixtures::member($left, $user, status: MembershipStatus::Removed);
        OrganizationFixtures::create(User::factory()->create(), 'Delta');

        $data = $this->asUser($user)->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 2)->json('data');

        $this->assertSame([[$mine->id, 'OWNER', 1], [$joined->id, 'ADMIN', 2]], array_map(fn ($o) => [$o['id'], $o['role'], $o['member_count']], $data));
    }

    public function test_an_organization_is_visible_to_its_members_only_and_looks_missing_to_everyone_else(): void
    {
        $owner = User::factory()->create();
        $organization = OrganizationFixtures::create($owner);
        $member = OrganizationFixtures::member($organization)->user;
        $stranger = User::factory()->create();
        $removed = OrganizationFixtures::member($organization, status: MembershipStatus::Removed)->user;
        $suspended = OrganizationFixtures::member($organization, status: MembershipStatus::Suspended)->user;

        $this->asUser($member)->getJson(self::URL."/{$organization->id}")->assertOk()->assertJsonPath('data.role', 'MEMBER');
        $missing = $this->asUser($stranger)->getJson(self::URL.'/01k6m2y5a7j1x9v3q8n4r2t6wz')->assertNotFound();
        foreach ([$stranger, $removed] as $outsider) {
            $foreign = $this->asUser($outsider)->getJson(self::URL."/{$organization->id}")->assertNotFound();
            $this->assertSame(collect($missing->json('error'))->except('request_id')->all(), collect($foreign->json('error'))->except('request_id')->all());
        }
        $this->asUser($suspended)->getJson(self::URL."/{$organization->id}")->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_SUSPENDED');
        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL."/{$organization->id}")->assertUnauthorized();
    }

    public function test_outsiders_get_not_found_before_their_input_is_validated(): void
    {
        $organization = OrganizationFixtures::create(User::factory()->create());
        $stranger = User::factory()->create();
        $base = self::URL."/{$organization->id}";

        // Invalid input from a non-member must not answer 422 and so confirm that the organization exists.
        $this->asUser($stranger)->patchJson($base, ['name' => ''])->assertNotFound();
        $this->asUser($stranger)->postJson("{$base}/projects", ['slug' => 'Not A Slug'])->assertNotFound();
        $this->asUser($stranger)->postJson("{$base}/invitations", ['email' => 'nope', 'role' => 'OWNER'])->assertNotFound();
        $this->asUser($stranger)->getJson("{$base}/members?per_page=1000")->assertNotFound();
        $this->asUser($stranger)->getJson("{$base}/audit-events?per_page=1000")->assertNotFound();
    }

    public function test_admins_rename_members_cannot_and_the_change_is_audited(): void
    {
        $owner = User::factory()->create();
        $organization = OrganizationFixtures::create($owner, 'Old');
        $admin = OrganizationFixtures::member($organization, role: OrganizationRole::Admin)->user;
        $member = OrganizationFixtures::member($organization)->user;

        $this->asUser($member)->patchJson(self::URL."/{$organization->id}", ['name' => 'Mine'])->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser($admin)->patchJson(self::URL."/{$organization->id}", ['name' => 'New'])->assertOk()->assertJsonPath('data.name', 'New');
        $this->asUser($admin)->patchJson(self::URL."/{$organization->id}", ['name' => 'X', 'slug' => 'x'])->assertUnprocessable();
        $this->asUser($admin)->patchJson(self::URL."/{$organization->id}", ['name' => 'X', 'owner_user_id' => $admin->id])->assertUnprocessable();

        $event = OrganizationAuditEvent::query()->where('action', 'ORGANIZATION_UPDATED')->sole();
        $this->assertEquals([$admin->id, ['name' => ['from' => 'Old', 'to' => 'New']]], [$event->actor_user_id, $event->metadata]);
        $this->assertSame($owner->id, $organization->refresh()->owner_user_id);
    }

    public function test_only_the_owner_archives_and_an_archived_organization_is_read_only(): void
    {
        $owner = User::factory()->create();
        $organization = OrganizationFixtures::create($owner);
        $admin = OrganizationFixtures::member($organization, role: OrganizationRole::Admin)->user;

        $this->asUser($admin)->postJson(self::URL."/{$organization->id}/archive")->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser($owner)->postJson(self::URL."/{$organization->id}/archive")->assertOk()->assertJsonPath('data.status', 'ARCHIVED');

        $this->asUser($admin)->getJson(self::URL."/{$organization->id}")->assertOk();
        $this->asUser($owner)->postJson(self::URL."/{$organization->id}/archive")->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_ARCHIVED');
        $this->asUser($owner)->patchJson(self::URL."/{$organization->id}", ['name' => 'Revived'])->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_ARCHIVED');
        $this->asUser($owner)->postJson(self::URL."/{$organization->id}/invitations", ['email' => 'a@example.com', 'role' => 'MEMBER'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_ARCHIVED');
        // Nothing is deleted, and an archived organization never becomes active again.
        $this->assertSame(2, OrganizationMembership::query()->count());
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('organizations')->update(['status' => 'ACTIVE'])), QueryException::class);
    }

    public function test_a_suspended_organization_is_read_only(): void
    {
        $owner = User::factory()->create();
        $organization = OrganizationFixtures::setStatus(OrganizationFixtures::create($owner), 'SUSPENDED');

        $this->asUser($owner)->getJson(self::URL."/{$organization->id}")->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->asUser($owner)->patchJson(self::URL."/{$organization->id}", ['name' => 'X'])->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_SUSPENDED');
        $this->asUser($owner)->postJson(self::URL."/{$organization->id}/projects", ['name' => 'P', 'slug' => 'p', 'source_type' => 'UPLOAD'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_SUSPENDED');
    }

    public function test_the_billing_context_is_for_admins_and_names_the_free_plan_and_seats(): void
    {
        $owner = User::factory()->create();
        $organization = OrganizationFixtures::create($owner);
        OrganizationFixtures::member($organization, role: OrganizationRole::Admin);
        $member = OrganizationFixtures::member($organization)->user;

        $data = $this->asUser($owner)->getJson(self::URL."/{$organization->id}/billing")->assertOk()->json('data');

        $this->assertSame(['key' => 'FREE', 'version' => '1.0.0', 'name' => 'Free'], $data['plan']);
        $this->assertSame(['limit' => 5, 'used' => 3, 'remaining' => 2], $data['seats']);
        $this->assertSame(['ACTIVE_PROJECTS', 3, 0], [$data['quotas'][0]['key'], $data['quotas'][0]['limit'], $data['quotas'][0]['used']]);
        $this->asUser($member)->getJson(self::URL."/{$organization->id}/billing")->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        // There is no way to change it from the API.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->asUser($owner)->json($method, self::URL."/{$organization->id}/billing", ['seat_limit' => 100, 'plan' => 'TEAM_READY'])->assertStatus(405);
        }
    }

    public function test_the_database_keeps_exactly_one_active_owner(): void
    {
        $owner = User::factory()->create();
        $organization = OrganizationFixtures::create($owner);
        $ownerMembership = OrganizationFixtures::membershipOf($organization, $owner);
        $other = OrganizationFixtures::member($organization);
        // The invariant is checked when a transaction commits; this test runs
        // inside RefreshDatabase's transaction, so check each statement now.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $attempts = [
            'owner demoted' => fn () => DB::table('organization_memberships')->where('id', $ownerMembership->id)->update(['role' => 'ADMIN']),
            'owner suspended' => fn () => DB::table('organization_memberships')->where('id', $ownerMembership->id)->update(['status' => 'SUSPENDED']),
            'owner removed' => fn () => DB::table('organization_memberships')->where('id', $ownerMembership->id)->update(['status' => 'REMOVED']),
            'second owner' => fn () => DB::table('organization_memberships')->where('id', $other->id)->update(['role' => 'OWNER']),
            'owner membership deleted' => fn () => DB::table('organization_memberships')->where('id', $ownerMembership->id)->delete(),
            'owner changed' => fn () => DB::table('organizations')->update(['owner_user_id' => $other->user_id]),
            'slug changed' => fn () => DB::table('organizations')->update(['slug' => 'taken-over']),
            'membership moved' => fn () => DB::table('organization_memberships')->where('id', $other->id)->update(['user_id' => User::factory()->create()->id]),
            'organization without owner' => fn () => DB::table('organizations')->insert(['id' => '01k6m2y5a7j1x9v3q8n4r2t6wz', 'owner_user_id' => $owner->id,
                'name' => 'Orphan', 'slug' => 'orphan', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("{$name} was allowed");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([OrganizationRole::Owner, MembershipStatus::Active], [$ownerMembership->refresh()->role, $ownerMembership->status]);
        $this->assertThrows(fn () => $organization->delete(), DomainRuleViolation::class);
        $this->assertThrows(fn () => $other->delete(), DomainRuleViolation::class);
    }
}
