<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Models\BillingUsageEvent;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BillingFixtures;
use Tests\Support\FakeGitHub;
use Tests\Support\GitHubFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24: organization-owned projects (docs/teams/teams-architecture.md#projects).
 * One ownership rule: organization_id decides; personal projects keep the
 * owner-only rule. Team projects are measured against the organization's
 * plan, never the creator's, and personal projects never against the
 * organization's (docs/teams/billing-boundary.md).
 */
final class TeamProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->organization = OrganizationFixtures::create($this->owner);
        $this->admin = OrganizationFixtures::member($this->organization, role: OrganizationRole::Admin)->user;
        $this->member = OrganizationFixtures::member($this->organization)->user;
    }

    private function create(User $as, string $slug = 'api', ?Organization $organization = null)
    {
        return $this->asUser($as)->postJson('/api/v1/organizations/'.($organization ?? $this->organization)->id.'/projects',
            ['name' => 'Team API', 'slug' => $slug, 'source_type' => 'UPLOAD', 'language' => 'php']);
    }

    private function quota(User|Organization $subject, string $key): int
    {
        $url = $subject instanceof Organization ? "/api/v1/organizations/{$subject->id}/billing" : '/api/v1/billing';
        $as = $subject instanceof Organization ? $this->owner : $subject;

        return collect($this->asUser($as)->getJson($url)->assertOk()->json('data.quotas'))->firstWhere('key', $key)['used'];
    }

    public function test_admins_create_team_projects_and_members_cannot(): void
    {
        $data = $this->create($this->admin)->assertCreated()->json('data');

        $project = Project::query()->sole();
        $this->assertSame([$this->organization->id, $this->admin->id, $this->organization->id], [$data['organization_id'], $project->user_id, $project->organization_id]);
        $this->create($this->member, 'other')->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->create(User::factory()->create(), 'stranger')->assertNotFound();
        $event = OrganizationAuditEvent::query()->where('action', 'PROJECT_CREATED')->sole();
        $this->assertSame([$this->admin->id, 'project', $project->id], [$event->actor_user_id, $event->target_type, $event->target_id]);
        $this->asUser($this->member)->getJson("/api/v1/organizations/{$this->organization->id}/projects")->assertOk()->assertJsonPath('data.0.id', $project->id);
    }

    public function test_team_and_personal_projects_never_mix(): void
    {
        $personal = $this->asUser($this->admin)->postJson('/api/v1/projects', ['name' => 'Mine', 'slug' => 'api', 'source_type' => 'UPLOAD',
            'organization_id' => $this->organization->id])->assertCreated()->json('data');
        // The same slug is free in the organization: slugs are unique per owner (personal) and per organization (team).
        $team = $this->create($this->admin)->assertCreated()->json('data');

        $this->assertNull($personal['organization_id'], 'POST /projects never creates a team project');
        $this->assertSame([$personal['id']], array_column($this->asUser($this->admin)->getJson('/api/v1/projects')->json('data'), 'id'));
        $this->assertSame([$team['id']], array_column($this->asUser($this->admin)->getJson("/api/v1/organizations/{$this->organization->id}/projects")->json('data'), 'id'));
        $this->create($this->admin)->assertUnprocessable()->assertJsonPath('error.details.fields.slug.0', 'The organization already has a project with this slug.');
        // Other members cannot see the admin's personal project.
        $this->asUser($this->member)->getJson("/api/v1/projects/{$personal['id']}")->assertNotFound();
        // A project's scope is fixed at creation (distinct slugs: only the trigger can refuse these).
        $lonePersonal = Project::factory()->for($this->member)->create(['slug' => 'lone-personal']);
        $loneTeam = OrganizationFixtures::project($this->organization, $this->admin, ['slug' => 'lone-team']);
        foreach ([
            fn () => DB::table('projects')->where('id', $lonePersonal->id)->update(['organization_id' => $this->organization->id]),
            fn () => DB::table('projects')->where('id', $loneTeam->id)->update(['organization_id' => null]),
            fn () => DB::table('projects')->where('id', $loneTeam->id)->update(['user_id' => $this->member->id]),
        ] as $change) {
            $this->assertThrows(fn () => DB::transaction($change), QueryException::class);
        }
    }

    public function test_members_work_on_team_projects_and_admins_manage_them(): void
    {
        $project = OrganizationFixtures::project($this->organization, $this->admin);
        $url = "/api/v1/projects/{$project->id}";

        $this->asUser($this->member)->getJson($url)->assertOk();
        $snapshot = SourceSnapshot::factory()->for($project)->create();
        $this->asUser($this->member)->postJson("{$url}/analyses", ['source_snapshot_id' => $snapshot->id])->assertStatus(202);
        $this->asUser($this->member)->patchJson($url, ['name' => 'Renamed'])->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser($this->member)->postJson("{$url}/archive")->assertForbidden();
        $this->asUser($this->admin)->patchJson($url, ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->asUser($this->owner)->postJson("{$url}/archive")->assertOk()->assertJsonPath('data.status', 'ARCHIVED');

        $archived = OrganizationAuditEvent::query()->where('action', 'PROJECT_ARCHIVED')->sole();
        $this->assertSame([$this->owner->id, $project->id], [$archived->actor_user_id, $archived->target_id]);
    }

    public function test_an_archived_organization_keeps_its_projects_readable_and_unchangeable(): void
    {
        $project = OrganizationFixtures::project($this->organization, $this->admin);
        $snapshot = SourceSnapshot::factory()->for($project)->create();
        OrganizationFixtures::setStatus($this->organization, 'ARCHIVED');
        $url = "/api/v1/projects/{$project->id}";

        $this->asUser($this->member)->getJson($url)->assertOk();
        $this->asUser($this->member)->getJson("{$url}/source-snapshots")->assertOk();
        $this->asUser($this->member)->postJson("{$url}/analyses", ['source_snapshot_id' => $snapshot->id])->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_ARCHIVED');
        $this->asUser($this->admin)->patchJson($url, ['name' => 'X'])->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_ARCHIVED');
        $this->asUser($this->owner)->postJson("{$url}/archive")->assertStatus(409);
        $this->create($this->owner, 'late')->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_ARCHIVED');
        $this->assertSame('ACTIVE', $project->refresh()->status->value);
    }

    public function test_suspended_and_removed_members_cannot_change_team_projects(): void
    {
        $project = OrganizationFixtures::project($this->organization, $this->admin);
        $snapshot = SourceSnapshot::factory()->for($project)->create();
        $url = "/api/v1/projects/{$project->id}/analyses";
        $suspended = OrganizationFixtures::member($this->organization, status: MembershipStatus::Suspended)->user;
        $removed = OrganizationFixtures::member($this->organization, status: MembershipStatus::Removed)->user;

        $this->asUser($suspended)->postJson($url, ['source_snapshot_id' => $snapshot->id])->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_SUSPENDED');
        $this->asUser($removed)->postJson($url, ['source_snapshot_id' => $snapshot->id])->assertNotFound();
        $this->assertSame(0, DB::table('analysis_runs')->count());
    }

    public function test_team_projects_use_the_organizations_quota_and_personal_ones_the_users(): void
    {
        // The admin is on PRO personally; the organization is on FREE (3 active projects).
        BillingFixtures::pro($this->admin);
        foreach (['a', 'b', 'c'] as $slug) {
            $this->create($this->admin, $slug)->assertCreated();
        }
        $this->create($this->admin, 'd')->assertStatus(402)->assertJsonPath('error.code', 'QUOTA_EXCEEDED')
            ->assertJsonPath('error.details.quota', 'ACTIVE_PROJECTS')->assertJsonPath('error.details.limit', 3);

        // The creator's personal plan is untouched by team projects, and still has room.
        $this->assertSame(0, $this->quota($this->admin, 'ACTIVE_PROJECTS'));
        $this->assertSame(3, $this->quota($this->organization, 'ACTIVE_PROJECTS'));
        $this->asUser($this->admin)->postJson('/api/v1/projects', ['name' => 'Mine', 'slug' => 'mine', 'source_type' => 'UPLOAD'])->assertCreated();
        $this->assertSame(1, $this->quota($this->admin, 'ACTIVE_PROJECTS'));
        $this->assertSame(3, $this->quota($this->organization, 'ACTIVE_PROJECTS'), 'a personal project never uses team quota');
    }

    public function test_usage_on_team_projects_is_charged_to_the_organization(): void
    {
        $team = OrganizationFixtures::project($this->organization, $this->admin);
        $personal = Project::factory()->for($this->member)->create();

        $this->asUser($this->member)->postJson("/api/v1/projects/{$team->id}/analyses", ['source_snapshot_id' => SourceSnapshot::factory()->for($team)->create()->id])->assertStatus(202);
        $this->asUser($this->member)->postJson("/api/v1/projects/{$personal->id}/analyses", ['source_snapshot_id' => SourceSnapshot::factory()->for($personal)->create()->id])->assertStatus(202);

        $this->assertSame(1, $this->quota($this->organization, 'ANALYSES'));
        $this->assertSame(1, $this->quota($this->member, 'ANALYSES'));
        $this->assertSame(0, $this->quota($this->admin, 'ANALYSES'), 'the creator of the team project is not charged');
        $events = BillingUsageEvent::query()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame([[null, $this->organization->id], [$this->member->id, null]], $events->map(fn ($e) => [$e->user_id, $e->organization_id])->all());
        // Personal usage lists never show the organization's usage.
        $this->asUser($this->admin)->getJson('/api/v1/billing/usage')->assertJsonPath('meta.total', 0);
        // The organization's plan applies to its projects: FREE has no AI assessment, whatever the member's own plan.
        BillingFixtures::pro($this->member);
        config(['codedna.ai.enabled' => true]);
        $this->asUser($this->member)->postJson("/api/v1/projects/{$team->id}/assessments", [])->assertStatus(402)->assertJsonPath('error.code', 'FEATURE_NOT_INCLUDED')
            ->assertJsonPath('error.details.plan', 'FREE');
        // ...and the ledger has exactly one subject per row.
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('billing_usage_events')->insert([
            'id' => strtolower((string) Str::ulid()), 'user_id' => $this->member->id, 'organization_id' => $this->organization->id,
            'billing_plan_id' => DB::table('billing_plans')->where('key', 'FREE')->value('id'), 'quota_key' => 'ANALYSES', 'outcome' => 'REJECTED', 'amount' => 1,
            'period_start' => now()->startOfMonth(), 'period_end' => now()->startOfMonth()->addMonth(),
        ])), QueryException::class);
    }

    public function test_team_membership_does_not_grant_personal_plans(): void
    {
        $this->asUser($this->member)->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'FREE')->assertJsonPath('data.source', 'FREE_FALLBACK');
        BillingFixtures::pro($this->owner);
        $this->asUser($this->member)->getJson('/api/v1/billing')->assertJsonPath('data.plan.key', 'FREE');
        $this->asUser($this->owner)->getJson("/api/v1/organizations/{$this->organization->id}/billing")->assertJsonPath('data.plan.key', 'FREE');
    }

    public function test_connecting_github_to_a_team_project_is_for_admins_with_their_own_github_access(): void
    {
        FakeGitHub::configure()->fake();
        $project = OrganizationFixtures::project($this->organization, $this->admin);
        GitHubFixtures::account($this->member);
        GitHubFixtures::account($this->admin);
        $url = "/api/v1/projects/{$project->id}/github";

        $this->asUser($this->member)->postJson($url, ['repository_id' => FakeGitHub::REPOSITORY_ID])->assertForbidden()->assertJsonPath('error.code', 'INSUFFICIENT_ORGANIZATION_ROLE');
        $this->asUser($this->admin)->postJson($url, ['repository_id' => FakeGitHub::REPOSITORY_ID])->assertCreated();
        // Members see the connection, never a token.
        $body = (string) $this->asUser($this->member)->getJson($url)->assertOk()->getContent();
        $this->assertStringNotContainsStringIgnoringCase('token', $body);
    }
}
