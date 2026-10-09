<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\Organizations\OrganizationRole;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Models\Project;
use App\Models\RepositoryProviderAccount;
use App\Models\RepositoryProviderConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGitHub;
use Tests\Support\FakeProviders;
use Tests\Support\GitHubFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\Support\ProviderFixtures;
use Tests\TestCase;

/**
 * Repository listing and project connections for GitLab and Bitbucket Cloud
 * (Phase 28): verified as the user, one repository source per project across
 * every provider, tenant isolation, and the provider's failures surfaced as
 * safe codes. Against FakeProviders (no live provider).
 */
final class ProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeProviders $providers;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->providers = FakeProviders::configure()->fake();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
        ProviderFixtures::account($this->owner, RepositoryProviderKey::GitLab);
        ProviderFixtures::account($this->owner, RepositoryProviderKey::Bitbucket);
    }

    /** @return iterable<string, array{RepositoryProviderKey}> */
    public static function providers(): iterable
    {
        yield 'GitLab' => [RepositoryProviderKey::GitLab];
        yield 'Bitbucket Cloud' => [RepositoryProviderKey::Bitbucket];
    }

    /** @return TestResponse<JsonResponse> */
    private function connect(RepositoryProviderKey $provider, array $extra = [], ?User $as = null, ?Project $project = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->postJson('/api/v1/projects/'.($project ?? $this->project)->id.'/repository-provider',
            ['provider' => $provider->value, 'repository_id' => ProviderFixtures::repositoryId($provider)] + $extra);
    }

    #[DataProvider('providers')]
    public function test_a_readable_repository_is_connected_with_facts_from_the_provider(RepositoryProviderKey $provider): void
    {
        $this->connect($provider)->assertCreated()
            ->assertJsonPath('data.provider', $provider->value)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.branch', 'main')
            ->assertJsonPath('data.repository.full_name', 'acme/billing-service')
            ->assertJsonPath('data.repository.private', true);

        $connection = RepositoryProviderConnection::query()->sole();
        $this->assertSame([$this->owner->id, ProviderFixtures::repositoryId($provider)], [$connection->connected_by, $connection->repository_id]);
        $show = $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/repository-provider")->assertOk();
        $show->assertJsonPath('data.connection.provider', $provider->value)->assertJsonPath('data.github_connected', false);
        $this->assertStringNotContainsString(FakeProviders::ACCESS_TOKEN, (string) $show->getContent());
        // Every request carried the user's token to the provider's own origin only.
        foreach ($this->providers->requests as $request) {
            $this->assertContains(parse_url($request->url(), PHP_URL_HOST), ['gitlab.test', 'api.bitbucket.test']);
        }
    }

    #[DataProvider('providers')]
    public function test_repositories_are_listed_page_by_page_as_the_user(RepositoryProviderKey $provider): void
    {
        $this->providers->pageSize = 1;
        $provider === RepositoryProviderKey::GitLab
            ? $this->providers->gitlabProjects[77] = FakeProviders::gitlabProject(77, 'acme/public-site', 'public')
            : $this->providers->bitbucketRepositories['{77777777-7777-7777-7777-777777777777}'] = FakeProviders::bitbucketRepository('{77777777-7777-7777-7777-777777777777}', 'public-site', false);

        $first = $this->asUser($this->owner)->getJson("/api/v1/repository-providers/{$provider->value}/repositories?per_page=1")->assertOk();
        $first->assertJsonPath('meta.has_more', true)->assertJsonCount(1, 'data')->assertJsonPath('data.0.private', true)->assertJsonPath('data.0.default_branch', 'main');
        $second = $this->asUser($this->owner)->getJson("/api/v1/repository-providers/{$provider->value}/repositories?per_page=1&page=2")->assertOk();
        $second->assertJsonPath('meta.has_more', false)->assertJsonPath('data.0.private', false);
        $this->asUser($this->owner)->getJson("/api/v1/repository-providers/{$provider->value}/repositories?page=51")->assertUnprocessable();
    }

    #[DataProvider('providers')]
    public function test_a_repository_the_user_cannot_read_or_an_invalid_id_is_refused(RepositoryProviderKey $provider): void
    {
        $unknown = $provider === RepositoryProviderKey::GitLab ? '999' : FakeProviders::BB_WORKSPACE.'/{99999999-9999-9999-9999-999999999999}';
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/repository-provider", ['provider' => $provider->value, 'repository_id' => $unknown])
            ->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_REPOSITORY_NOT_FOUND');
        $count = count($this->providers->requests);
        foreach (['../etc', 'https://evil.example/x', '12/34'] as $bad) {
            $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/repository-provider", ['provider' => $provider->value, 'repository_id' => $bad])->assertUnprocessable();
        }
        $this->assertCount($count, $this->providers->requests, 'invalid IDs never reach the provider');
        $this->assertSame(0, RepositoryProviderConnection::query()->count());
    }

    #[DataProvider('providers')]
    public function test_a_missing_or_invalid_branch_is_refused(RepositoryProviderKey $provider): void
    {
        $this->connect($provider, ['branch' => 'does-not-exist'])->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_BRANCH_NOT_FOUND');
        $this->connect($provider, ['branch' => '../../main'])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->connect($provider, ['url' => 'https://evil.example'])->assertUnprocessable();
    }

    public function test_an_empty_repository_without_a_default_branch_is_refused(): void
    {
        $this->providers->gitlabProjects[FakeProviders::GITLAB_PROJECT]['default_branch'] = null;
        $this->connect(RepositoryProviderKey::GitLab)->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_BRANCH_NOT_FOUND');
    }

    public function test_branches_are_listed_and_changed_after_verification(): void
    {
        $this->providers->branches['gitlab:'.FakeProviders::GITLAB_PROJECT]['release/1.0'] = str_repeat('b', 40);
        $this->connect(RepositoryProviderKey::GitLab)->assertCreated();
        $branches = $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/repository-provider/branches")->assertOk();
        $this->assertSame(['main', 'release/1.0'], $branches->json('data'));

        $this->asUser($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/repository-provider", ['branch' => 'release/1.0'])->assertOk()->assertJsonPath('data.branch', 'release/1.0');
        $this->asUser($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/repository-provider", ['branch' => 'gone'])->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_BRANCH_NOT_FOUND');
    }

    public function test_one_repository_source_per_project_across_every_provider(): void
    {
        $this->connect(RepositoryProviderKey::GitLab)->assertCreated();
        $this->connect(RepositoryProviderKey::Bitbucket)->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_ALREADY_CONNECTED');

        // GitHub refuses while GitLab is connected.
        FakeGitHub::configure()->fake();
        GitHubFixtures::account($this->owner);
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/github", ['repository_id' => FakeGitHub::REPOSITORY_ID])
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_ALREADY_CONNECTED');

        // And GitLab/Bitbucket refuse while GitHub is connected.
        $other = Project::factory()->for($this->owner)->create();
        GitHubFixtures::connection($other);
        $this->providers = FakeProviders::configure()->fake();
        $this->connect(RepositoryProviderKey::Bitbucket, project: $other)->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_ALREADY_CONNECTED');
    }

    public function test_disconnecting_keeps_the_record_and_allows_a_new_source(): void
    {
        $this->connect(RepositoryProviderKey::GitLab)->assertCreated();
        $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/repository-provider")->assertOk()->assertJsonPath('data.status', 'DISCONNECTED');
        $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/repository-provider")->assertStatus(409)->assertJsonPath('error.code', 'PROVIDER_NOT_CONNECTED');
        $this->connect(RepositoryProviderKey::Bitbucket)->assertCreated();
        $this->assertSame(2, RepositoryProviderConnection::query()->count());
    }

    public function test_other_users_and_team_members_without_the_role_cannot_connect(): void
    {
        $stranger = User::factory()->create();
        ProviderFixtures::account($stranger, RepositoryProviderKey::GitLab, 'stranger-token', providerUserId: '9100');
        $this->connect(RepositoryProviderKey::GitLab, as: $stranger)->assertNotFound();
        $this->asUser($stranger)->getJson("/api/v1/projects/{$this->project->id}/repository-provider")->assertNotFound();
        $this->asUser($stranger)->deleteJson("/api/v1/projects/{$this->project->id}/repository-provider")->assertNotFound();

        $organization = OrganizationFixtures::create($this->owner);
        $teamProject = OrganizationFixtures::project($organization, $this->owner);
        $member = OrganizationFixtures::member($organization)->user;
        $admin = OrganizationFixtures::member($organization, role: OrganizationRole::Admin)->user;
        ProviderFixtures::account($member, RepositoryProviderKey::Bitbucket, providerUserId: '{00000000-0000-0000-0000-000000000001}');
        $this->asUser($member)->getJson("/api/v1/projects/{$teamProject->id}/repository-provider")->assertOk();
        $this->connect(RepositoryProviderKey::Bitbucket, as: $member, project: $teamProject)->assertForbidden();

        ProviderFixtures::account($admin, RepositoryProviderKey::GitLab, providerUserId: '9002');
        $this->connect(RepositoryProviderKey::GitLab, as: $admin, project: $teamProject)->assertCreated()->assertJsonPath('data.provider', 'gitlab');
        $this->assertSame($admin->id, RepositoryProviderConnection::query()->where('project_id', $teamProject->id)->sole()->connected_by);
    }

    public function test_without_an_account_the_user_must_authorize_first(): void
    {
        RepositoryProviderAccount::query()->delete();
        $this->connect(RepositoryProviderKey::GitLab)->assertStatus(409)->assertJsonPath('error.code', 'PROVIDER_AUTH_REQUIRED');
        $this->assertSame([], $this->providers->requests);
    }

    #[DataProvider('providers')]
    public function test_an_expired_token_is_refreshed_and_a_refused_refresh_requires_authorization(RepositoryProviderKey $provider): void
    {
        RepositoryProviderAccount::query()->where('provider', $provider->value)->update(['access_token_expires_at' => Carbon::now()->subMinute()]);
        $this->connect($provider)->assertCreated();
        $this->assertSame(FakeProviders::REFRESHED_TOKEN, RepositoryProviderAccount::query()->where('provider', $provider->value)->sole()->access_token);

        $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/repository-provider")->assertOk();
        $account = RepositoryProviderAccount::query()->where('provider', $provider->value)->sole();
        $account->forceFill(['access_token_expires_at' => Carbon::now()->subMinute(), 'refresh_token' => 'revoked-refresh-token'])->save();
        $this->connect($provider)->assertStatus(409)->assertJsonPath('error.code', 'PROVIDER_AUTH_REQUIRED');
    }

    #[DataProvider('providers')]
    public function test_revoked_credentials_rate_limits_and_outages_become_safe_codes(RepositoryProviderKey $provider): void
    {
        $this->providers->validTokens = ['some-other-token'];
        $this->connect($provider)->assertStatus(409)->assertJsonPath('error.code', 'PROVIDER_AUTH_REQUIRED');

        $this->providers->validTokens = [FakeProviders::ACCESS_TOKEN];
        $this->providers->override = fn () => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '42']);
        $this->connect($provider)->assertStatus(429)->assertJsonPath('error.code', 'PROVIDER_RATE_LIMITED')->assertHeader('Retry-After', '42');

        $this->providers->override = fn () => Http::response('upstream secret details', 503);
        $response = $this->connect($provider)->assertStatus(503)->assertJsonPath('error.code', 'PROVIDER_UNAVAILABLE');
        $this->assertStringNotContainsString('upstream secret details', (string) $response->getContent());
    }

    public function test_malformed_provider_responses_are_refused(): void
    {
        $this->providers->override = fn ($request) => str_contains($request->url(), '/projects/'.FakeProviders::GITLAB_PROJECT) && ! str_contains($request->url(), '/repository/')
            ? Http::response(['id' => FakeProviders::GITLAB_PROJECT, 'path_with_namespace' => '../../etc', 'visibility' => 'private', 'archived' => false])
            : null;
        $this->connect(RepositoryProviderKey::GitLab)->assertStatus(503)->assertJsonPath('error.code', 'PROVIDER_UNAVAILABLE');

        // A response about another repository than the one asked for is refused.
        $this->providers->override = fn ($request) => str_contains($request->url(), '/projects/'.FakeProviders::GITLAB_PROJECT) && ! str_contains($request->url(), '/repository/')
            ? Http::response(FakeProviders::gitlabProject(5, 'acme/other', 'private'))
            : null;
        $this->connect(RepositoryProviderKey::GitLab)->assertStatus(503);
        $this->assertSame(0, RepositoryProviderConnection::query()->count());
    }

    public function test_archived_projects_cannot_connect(): void
    {
        $this->project->forceFill(['status' => 'ARCHIVED'])->save();
        $this->connect(RepositoryProviderKey::GitLab)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
    }
}
