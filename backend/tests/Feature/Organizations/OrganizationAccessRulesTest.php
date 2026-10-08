<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\ChangeMembership;
use App\Actions\Organizations\InviteMember;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\ApiException;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24: the role rules themselves (docs/teams/authorization.md), and the
 * actions refusing OWNER or above-own roles even when request validation is
 * bypassed (defense in depth).
 */
final class OrganizationAccessRulesTest extends TestCase
{
    use RefreshDatabase;

    private function membership(OrganizationRole $role, string $id): OrganizationMembership
    {
        return (new OrganizationMembership)->forceFill(['id' => $id, 'role' => $role]);
    }

    public function test_who_outranks_whom_and_which_roles_can_be_given(): void
    {
        $access = new OrganizationAccess;
        $owner = $this->membership(OrganizationRole::Owner, 'o');
        $admin = $this->membership(OrganizationRole::Admin, 'a');
        $otherAdmin = $this->membership(OrganizationRole::Admin, 'b');
        $member = $this->membership(OrganizationRole::Member, 'm');

        $this->assertSame([true, true, true, false, false, false, false],
            [$access->outranks($owner, $admin), $access->outranks($owner, $member), $access->outranks($admin, $member),
                $access->outranks($admin, $otherAdmin), $access->outranks($admin, $owner), $access->outranks($member, $member), $access->outranks($owner, $owner)]);
        $this->assertSame([false, true, true, false, true, false, false, true],
            [$access->mayAssign($owner, OrganizationRole::Owner), $access->mayAssign($owner, OrganizationRole::Admin), $access->mayAssign($owner, OrganizationRole::Member),
                $access->mayAssign($admin, OrganizationRole::Owner), $access->mayAssign($admin, OrganizationRole::Admin),
                $access->mayAssign($member, OrganizationRole::Admin), $access->mayAssign($member, OrganizationRole::Owner), $access->mayAssign($member, OrganizationRole::Member)]);
    }

    public function test_the_actions_never_give_the_owner_role(): void
    {
        $owner = User::factory()->create();
        $organization = OrganizationFixtures::create($owner);
        $member = OrganizationFixtures::member($organization);

        foreach ([
            fn () => app(InviteMember::class)->handle($organization, $owner, 'x@example.com', OrganizationRole::Owner),
            fn () => app(ChangeMembership::class)->handle($organization, $owner, $member, OrganizationRole::Owner, null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('OWNER was given');
            } catch (ApiException $e) {
                $this->assertSame('INSUFFICIENT_ORGANIZATION_ROLE', $e->errorCode->value);
            }
        }
        $this->assertSame(OrganizationRole::Member, $member->refresh()->role);
    }
}
