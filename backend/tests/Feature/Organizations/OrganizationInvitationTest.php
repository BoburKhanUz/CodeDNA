<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24: invitations (docs/teams/invitations.md). Tokens are 256-bit,
 * shown once, stored only as a hash, single-use, short-lived and bound to
 * the invited email.
 */
final class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20T10:00:00Z'));
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e->message.' '.json_encode($e->context));
        $this->owner = User::factory()->create();
        $this->organization = OrganizationFixtures::create($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invite(string $email = 'Invitee@Example.com', string $role = 'MEMBER', ?User $as = null): array
    {
        return $this->asUser($as ?? $this->owner)->postJson("/api/v1/organizations/{$this->organization->id}/invitations", ['email' => $email, 'role' => $role])
            ->assertCreated()->json('data');
    }

    private function accept(User $user, string $token)
    {
        return $this->asUser($user)->postJson("/api/v1/organizations/invitations/{$token}/accept");
    }

    public function test_an_invitation_returns_its_token_once_and_stores_only_the_hash(): void
    {
        $data = $this->invite();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $data['token']);
        $this->assertSame(['invitee@example.com', 'MEMBER', 'PENDING', '2026-10-23T10:00:00Z'], [$data['email'], $data['role'], $data['status'], $data['expires_at']]);
        $invitation = OrganizationInvitation::query()->sole();
        $this->assertSame(hash('sha256', $data['token']), $invitation->token_hash);
        $this->assertArrayNotHasKey('token_hash', $data);
        // The token is nowhere in the database, the list, the audit log or the logs.
        $dump = json_encode([DB::table('organization_invitations')->get(), DB::table('organization_audit_events')->get()]);
        $this->assertStringNotContainsString($data['token'], (string) $dump);
        $list = (string) $this->asUser($this->owner)->getJson("/api/v1/organizations/{$this->organization->id}/invitations")->assertOk()->getContent();
        $this->assertStringNotContainsString($data['token'], $list);
        $this->assertStringNotContainsString($invitation->token_hash, $list);
        $this->assertStringNotContainsString($data['token'], implode("\n", $this->logged));
        $this->assertSame(['ORGANIZATION_CREATED', 'MEMBER_INVITED'], OrganizationAuditEvent::query()->orderBy('created_at')->orderBy('id')->pluck('action')->map->value->all());
    }

    public function test_accepting_makes_the_invited_user_an_active_member_once(): void
    {
        $token = $this->invite('invitee@example.com', 'ADMIN')['token'];
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $this->asUser($invitee)->getJson("/api/v1/organizations/{$this->organization->id}")->assertNotFound();

        $response = $this->accept($invitee, $token)->assertOk();

        $response->assertJsonPath('data.organization.id', $this->organization->id)->assertJsonPath('data.organization.role', 'ADMIN')
            ->assertJsonPath('data.membership.status', 'ACTIVE');
        $this->asUser($invitee)->getJson("/api/v1/organizations/{$this->organization->id}")->assertOk();
        $invitation = OrganizationInvitation::query()->sole();
        $this->assertSame($invitee->id, $invitation->accepted_by_user_id);
        $joined = OrganizationAuditEvent::query()->where('action', 'MEMBER_JOINED')->sole();
        $this->assertSame([$invitee->id, 'membership'], [$joined->actor_user_id, $joined->target_type]);
        // Single use: a replay changes nothing.
        $this->accept($invitee, $token)->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ALREADY_ACCEPTED');
        $this->assertSame(2, OrganizationMembership::query()->count());
        $this->assertStringNotContainsString($token, implode("\n", $this->logged));
    }

    public function test_only_the_invited_email_can_accept_compared_in_canonical_form(): void
    {
        $token = $this->invite('  Jane.Doe@Example.COM ')['token'];

        $this->accept(User::factory()->create(['email' => 'someone@example.com']), $token)->assertForbidden()->assertJsonPath('error.code', 'INVITATION_EMAIL_MISMATCH');
        $this->assertNull(OrganizationInvitation::query()->sole()->accepted_at, 'a wrong account leaves the invitation open');
        $this->accept(User::factory()->create(['email' => 'jane.doe@example.com']), $token)->assertOk();
    }

    public function test_revoked_and_expired_invitations_cannot_be_used(): void
    {
        $revoked = $this->invite('a@example.com');
        $this->asUser($this->owner)->postJson("/api/v1/organizations/{$this->organization->id}/invitations/{$revoked['id']}/revoke")
            ->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->accept(User::factory()->create(['email' => 'a@example.com']), $revoked['token'])->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_REVOKED');
        $this->asUser($this->owner)->postJson("/api/v1/organizations/{$this->organization->id}/invitations/{$revoked['id']}/revoke")
            ->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_REVOKED');

        $expired = $this->invite('b@example.com');
        Carbon::setTestNow(Carbon::now()->addHours(72));
        $this->accept(User::factory()->create(['email' => 'b@example.com']), $expired['token'])->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_EXPIRED');

        $this->assertSame(1, OrganizationMembership::query()->count());
        $this->assertSame(1, OrganizationAuditEvent::query()->where('action', 'INVITATION_REVOKED')->count());
    }

    public function test_unknown_and_malformed_tokens_look_the_same(): void
    {
        $user = User::factory()->create();
        $unknown = $this->accept($user, str_repeat('A', 43))->assertNotFound();
        foreach (['short', str_repeat('A', 44), str_repeat('A', 42).'!'] as $bad) {
            $response = $this->accept($user, rawurlencode($bad));
            $this->assertSame(404, $response->status());
        }
        $this->assertSame('RESOURCE_NOT_FOUND', $unknown->json('error.code'));
        $this->getJson('/api/v1/organizations/invitations/'.str_repeat('B', 43))->assertNotFound();
    }

    public function test_the_preview_shows_only_what_the_link_holder_needs(): void
    {
        $data = $this->invite('jane@example.com', 'ADMIN');
        $this->app['auth']->forgetGuards();

        $preview = $this->getJson("/api/v1/organizations/invitations/{$data['token']}")->assertOk()->json('data');

        $this->assertSame(['type' => 'organization_invitation_preview', 'status' => 'PENDING', 'organization' => ['name' => 'Acme Engineering'],
            'role' => 'ADMIN', 'expires_at' => '2026-10-23T10:00:00Z', 'email_hint' => 'j…@example.com'], $preview);
        $this->asUser($this->owner)->postJson("/api/v1/organizations/{$this->organization->id}/invitations/{$data['id']}/revoke")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertSame(['type' => 'organization_invitation_preview', 'status' => 'REVOKED', 'organization' => null, 'role' => null, 'expires_at' => null,
            'email_hint' => null], $this->getJson("/api/v1/organizations/invitations/{$data['token']}")->assertOk()->json('data'));
        // Accepting needs a session.
        $this->postJson("/api/v1/organizations/invitations/{$data['token']}/accept")->assertUnauthorized();
    }

    public function test_who_may_invite_and_with_which_role(): void
    {
        $admin = OrganizationFixtures::member($this->organization, role: OrganizationRole::Admin)->user;
        $member = OrganizationFixtures::member($this->organization)->user;
        $url = "/api/v1/organizations/{$this->organization->id}/invitations";

        $this->asUser($member)->postJson($url, ['email' => 'x@example.com', 'role' => 'MEMBER'])->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser($member)->getJson($url)->assertForbidden();
        $this->asUser($admin)->postJson($url, ['email' => 'y@example.com', 'role' => 'ADMIN'])->assertCreated();
        $this->asUser($this->owner)->postJson($url, ['email' => 'z@example.com', 'role' => 'OWNER'])->assertUnprocessable();
        $this->asUser(User::factory()->create())->postJson($url, ['email' => 'w@example.com', 'role' => 'MEMBER'])->assertNotFound();
        foreach ([['email' => 'not-an-email', 'role' => 'MEMBER'], ['email' => 'a@example.com'], ['email' => ['a@example.com'], 'role' => 'MEMBER']] as $body) {
            $this->asUser($this->owner)->postJson($url, $body)->assertUnprocessable();
        }
    }

    public function test_current_members_are_not_invited_again_and_a_new_invitation_replaces_an_open_one(): void
    {
        $member = OrganizationFixtures::member($this->organization, User::factory()->create(['email' => 'member@example.com']))->user;
        $url = "/api/v1/organizations/{$this->organization->id}/invitations";

        $this->asUser($this->owner)->postJson($url, ['email' => 'MEMBER@example.com', 'role' => 'MEMBER'])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_A_MEMBER');

        $first = $this->invite('new@example.com');
        $second = $this->invite('new@example.com');
        $this->assertSame(['REVOKED', 'PENDING'], [OrganizationInvitation::query()->findOrFail($first['id'])->statusAt(Carbon::now())->value,
            OrganizationInvitation::query()->findOrFail($second['id'])->statusAt(Carbon::now())->value]);
        $this->accept(User::factory()->create(['email' => 'new@example.com']), $first['token'])->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_REVOKED');
        $this->assertNotNull($member);
    }

    public function test_acceptance_needs_a_free_seat_and_an_active_organization(): void
    {
        foreach (range(1, 4) as $i) {
            OrganizationFixtures::member($this->organization);
        }
        $token = $this->invite('late@example.com')['token'];
        $late = User::factory()->create(['email' => 'late@example.com']);

        $this->accept($late, $token)->assertStatus(402)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED')->assertJsonPath('error.details.limit', 5);
        $this->assertNull(OrganizationInvitation::query()->sole()->accepted_at, 'a refused acceptance can be retried once a seat is free');

        OrganizationFixtures::setStatus($this->organization, 'ARCHIVED');
        $this->accept($late, $token)->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_ARCHIVED');
        $this->assertSame(0, OrganizationMembership::query()->where('user_id', $late->id)->count());
    }

    public function test_a_removed_member_returns_only_through_an_invitation_and_a_suspended_one_not_at_all(): void
    {
        $removed = OrganizationFixtures::member($this->organization, User::factory()->create(['email' => 'back@example.com']), status: MembershipStatus::Removed);
        $suspended = OrganizationFixtures::member($this->organization, User::factory()->create(['email' => 'held@example.com']), status: MembershipStatus::Suspended);

        $this->accept($removed->user, $this->invite('back@example.com', 'ADMIN')['token'])->assertOk();
        $this->assertSame([MembershipStatus::Active, OrganizationRole::Admin], [$removed->refresh()->status, $removed->role]);
        $this->assertSame(1, OrganizationMembership::query()->where('user_id', $removed->user_id)->count(), 'the same membership row is reused');

        $this->asUser($this->owner)->postJson("/api/v1/organizations/{$this->organization->id}/invitations", ['email' => 'held@example.com', 'role' => 'MEMBER'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_A_MEMBER');
        $this->assertSame(MembershipStatus::Suspended, $suspended->refresh()->status);
    }

    public function test_a_member_suspended_after_being_invited_cannot_accept(): void
    {
        $user = User::factory()->create(['email' => 'held@example.com']);
        $token = $this->invite('held@example.com')['token'];
        OrganizationFixtures::member($this->organization, $user, status: MembershipStatus::Suspended);

        $this->accept($user, $token)->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_SUSPENDED');
        $this->assertSame(MembershipStatus::Suspended, OrganizationFixtures::membershipOf($this->organization, $user)->status);
        $this->assertNull(OrganizationInvitation::query()->sole()->accepted_at);
    }

    public function test_decided_invitations_never_change_again(): void
    {
        $data = $this->invite('jane@example.com');
        $this->accept(User::factory()->create(['email' => 'jane@example.com']), $data['token'])->assertOk();

        foreach ([['accepted_at' => null, 'accepted_by_user_id' => null], ['revoked_at' => now()], ['role' => 'ADMIN'], ['expires_at' => now()->addYear()],
            ['token_hash' => str_repeat('a', 64)]] as $change) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('organization_invitations')->update($change)), QueryException::class);
        }
    }

    public function test_invitation_links_are_rate_limited_against_token_guessing(): void
    {
        $guesser = User::factory()->create();
        $limit = (int) config('codedna.rate_limits.invitation_accept_per_minute');
        for ($i = 0; $i < $limit; $i++) {
            $this->accept($guesser, str_repeat('G', 42).chr(65 + $i))->assertNotFound();
        }
        $this->accept($guesser, str_repeat('G', 43))->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');

        $this->app['auth']->forgetGuards();
        $preview = (int) config('codedna.rate_limits.invitation_preview_per_minute_per_ip');
        for ($i = 0; $i < $preview; $i++) {
            $this->getJson('/api/v1/organizations/invitations/'.str_repeat('P', 43))->assertNotFound();
        }
        $this->getJson('/api/v1/organizations/invitations/'.str_repeat('P', 43))->assertStatus(429);
    }
}
