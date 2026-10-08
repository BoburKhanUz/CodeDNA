<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\OrganizationAuditEvent;
use App\Models\User;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24: the organization audit log is complete, append-only, ordered
 * and visible to ADMINs and the OWNER only.
 */
final class OrganizationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_lifecycle_is_recorded_in_order_with_actors_targets_and_request_ids(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $invitee = User::factory()->create(['email' => 'dev@example.com']);
        $orgId = $this->asUser($owner)->postJson('/api/v1/organizations', ['name' => 'Acme'])->json('data.id');
        $base = "/api/v1/organizations/{$orgId}";

        $this->asUser($owner)->patchJson($base, ['name' => 'Acme Inc']);
        $revoked = $this->asUser($owner)->postJson("{$base}/invitations", ['email' => 'gone@example.com', 'role' => 'MEMBER'])->json('data.id');
        $this->asUser($owner)->postJson("{$base}/invitations/{$revoked}/revoke");
        $token = $this->asUser($owner)->postJson("{$base}/invitations", ['email' => 'dev@example.com', 'role' => 'MEMBER'])->json('data.token');
        $membership = $this->asUser($invitee)->postJson("/api/v1/organizations/invitations/{$token}/accept")->json('data.membership.id');
        $this->asUser($owner)->patchJson("{$base}/members/{$membership}", ['role' => 'ADMIN']);
        $this->asUser($owner)->patchJson("{$base}/members/{$membership}", ['status' => 'SUSPENDED']);
        $this->asUser($owner)->patchJson("{$base}/members/{$membership}", ['status' => 'ACTIVE']);
        $project = $this->asUser($invitee)->postJson("{$base}/projects", ['name' => 'P', 'slug' => 'p', 'source_type' => 'UPLOAD'])->json('data.id');
        $this->asUser($owner)->postJson("/api/v1/projects/{$project}/archive");
        $this->asUser($owner)->deleteJson("{$base}/members/{$membership}");
        $this->asUser($owner)->postJson("{$base}/archive");

        $events = $this->asUser($owner)->getJson("{$base}/audit-events?per_page=100")->assertOk()->json('data');
        $this->assertSame(array_reverse([
            'ORGANIZATION_CREATED', 'ORGANIZATION_UPDATED', 'MEMBER_INVITED', 'INVITATION_REVOKED', 'MEMBER_INVITED', 'MEMBER_JOINED',
            'MEMBER_ROLE_CHANGED', 'MEMBER_SUSPENDED', 'MEMBER_REACTIVATED', 'PROJECT_CREATED', 'PROJECT_ARCHIVED', 'MEMBER_REMOVED', 'ORGANIZATION_ARCHIVED',
        ]), array_column($events, 'action'), 'newest first');
        $every = array_map(fn (OrganizationAuditAction $a): string => $a->value, OrganizationAuditAction::cases());
        $seen = array_values(array_unique(array_column($events, 'action')));
        sort($every);
        sort($seen);
        $this->assertSame($every, $seen, 'every audit action is exercised');
        $joined = collect($events)->firstWhere('action', 'MEMBER_JOINED');
        $this->assertSame([$invitee->id, ['type' => 'membership', 'id' => $membership]], [$joined['actor']['id'], $joined['target']]);
        foreach ($events as $event) {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $event['request_id']);
        }
        $this->assertStringNotContainsString($token, (string) json_encode($events));
    }

    public function test_only_admins_and_the_owner_read_the_log_and_nobody_writes_it(): void
    {
        $owner = User::factory()->create();
        $organization = OrganizationFixtures::create($owner);
        $admin = OrganizationFixtures::member($organization, role: OrganizationRole::Admin)->user;
        $member = OrganizationFixtures::member($organization)->user;
        $url = "/api/v1/organizations/{$organization->id}/audit-events";

        $this->asUser($admin)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
        $this->asUser($member)->getJson($url)->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser(User::factory()->create())->getJson($url)->assertNotFound();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->asUser($owner)->json($method, $url, ['action' => 'ORGANIZATION_CREATED'])->assertStatus(405);
        }
        $this->asUser($owner)->getJson($url.'?per_page=1000')->assertUnprocessable();
    }

    public function test_entries_are_append_only(): void
    {
        $organization = OrganizationFixtures::create(User::factory()->create());
        $event = OrganizationAuditEvent::query()->sole();

        $this->assertThrows(fn () => $event->forceFill(['action' => OrganizationAuditAction::MemberRemoved])->save(), DomainRuleViolation::class);
        $this->assertThrows(fn () => $event->delete(), DomainRuleViolation::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('organization_audit_events')->update(['metadata' => '{}'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('organization_audit_events')->insert(['id' => strtolower((string) Str::ulid()),
            'organization_id' => $organization->id, 'action' => 'MADE_UP', 'metadata' => '{}'])), QueryException::class);
    }

    public function test_metadata_never_takes_secret_looking_keys(): void
    {
        $organization = OrganizationFixtures::create(User::factory()->create());
        foreach (['token', 'invitation_token', 'password', 'session_id', 'github_access_token', 'api_key', 'source', 'nested'] as $key) {
            $metadata = $key === 'nested' ? ['outer' => ['secret' => 'x']] : [$key => 'x'];
            $this->assertThrows(fn () => app(OrganizationAudit::class)->record($organization, null, OrganizationAuditAction::OrganizationUpdated, null, $metadata), LogicException::class);
        }
        $this->assertSame(1, OrganizationAuditEvent::query()->count());
    }
}
