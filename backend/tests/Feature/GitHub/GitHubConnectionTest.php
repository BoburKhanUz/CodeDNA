<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGitHub;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

/**
 * Connecting a project to a GitHub repository (Phase 19): every repository,
 * installation and branch fact is verified with GitHub; the client sends
 * only a repository ID and a branch name.
 */
final class GitHubConnectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->github = FakeGitHub::configure()->fake();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
        GitHubFixtures::account($this->owner);
    }

    private function path(string $suffix = '', ?Project $project = null): string
    {
        return '/api/v1/projects/'.($project ?? $this->project)->id."/github{$suffix}";
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function connect(array $body = ['repository_id' => FakeGitHub::REPOSITORY_ID], ?User $as = null, ?Project $project = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->postJson($this->path('', $project), $body);
    }

    public function test_a_project_without_a_connection(): void
    {
        $this->asUser($this->owner)->getJson($this->path())->assertOk()->assertExactJson(['data' => [
            'configured' => true, 'account_connected' => true, 'connection' => null, 'latest_import' => null,
        ]]);
    }

    public function test_a_repository_is_connected_with_facts_verified_by_github(): void
    {
        $response = $this->connect()->assertCreated();

        $response->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.repository', [
                'id' => FakeGitHub::REPOSITORY_ID, 'owner' => 'octo-org', 'name' => 'billing-service', 'full_name' => 'octo-org/billing-service',
                'private' => true, 'archived' => false, 'default_branch' => 'main',
            ])
            ->assertJsonPath('data.branch', 'main')
            ->assertJsonPath('data.last_imported_commit_sha', null);
        $connection = GitHubConnection::query()->sole();
        $this->assertSame([FakeGitHub::INSTALLATION_ID, $this->owner->id], [$connection->installation_id, $connection->user_id]);
        $body = (string) $response->getContent();
        foreach (['installation', FakeGitHub::API, 'token', 'clone_url'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
        // The installation ID as a JSON value (a bare "77" also occurs inside random ULIDs and timestamps).
        $this->assertDoesNotMatchRegularExpression('/[:\[,]\s*"?'.FakeGitHub::INSTALLATION_ID.'"?\s*[,\]}]/', $body);
        // Verified as the user (repository, branch) and as the App (installation).
        $this->assertSame('Bearer '.FakeGitHub::USER_TOKEN, $this->github->sent('GET', '#^/repositories/\d+$#')[0]->header('Authorization')[0]);
        $this->assertCount(1, $this->github->sent('GET', '#/branches/main$#'));
        $this->assertCount(1, $this->github->sent('GET', '#/installation$#'));
        $this->asUser($this->owner)->getJson($this->path())->assertJsonPath('data.connection.repository.full_name', 'octo-org/billing-service');
    }

    public function test_a_branch_can_be_chosen(): void
    {
        $this->github->branches[FakeGitHub::REPOSITORY_ID]['feature/new-parser'] = str_repeat('b', 40);

        $this->connect(['repository_id' => FakeGitHub::REPOSITORY_ID, 'branch' => 'feature/new-parser'])->assertCreated()->assertJsonPath('data.branch', 'feature/new-parser');
        $this->assertCount(1, $this->github->sent('GET', '#/branches/feature/new-parser$#'));
    }

    public function test_a_repository_the_user_cannot_see_is_refused(): void
    {
        $this->connect(['repository_id' => 99999999])->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_REPOSITORY_NOT_FOUND');
        $this->github->repository(555, 'octo-org', 'disabled-repo', ['disabled' => true]);
        $this->connect(['repository_id' => 555])->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_REPOSITORY_NOT_FOUND');
        $this->assertSame(0, GitHubConnection::query()->count());
    }

    public function test_a_response_about_another_repository_is_refused(): void
    {
        $this->github->override = fn (Request $r) => preg_match('#/repositories/\d+$#', $r->url()) === 1
            ? Http::response(['id' => 42] + $this->github->repositories[FakeGitHub::REPOSITORY_ID]) : null;

        $this->connect()->assertStatus(503)->assertJsonPath('error.code', 'GITHUB_UNAVAILABLE');
        $this->assertSame(0, GitHubConnection::query()->count());
    }

    public function test_a_repository_without_the_app_installed_is_refused(): void
    {
        $this->github->override = fn (Request $r) => str_ends_with($r->url(), '/installation') ? Http::response(['message' => 'Not Found'], 404) : null;

        $this->connect()->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_INSTALLATION_REQUIRED');
        $this->assertSame(0, GitHubConnection::query()->count());
    }

    /**
     * Phase 21: a public repository is readable with any user token, but its
     * installation (another organization's) is not the user's: refused.
     */
    public function test_another_accounts_installation_is_refused(): void
    {
        $this->github->userInstallations = [999];

        $this->connect()->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_INSTALLATION_REQUIRED');
        $this->assertSame(0, GitHubConnection::query()->count());
        $this->assertCount(0, $this->github->sent('POST', '#/access_tokens$#'), 'no installation token is minted');
    }

    public function test_a_missing_branch_is_refused_and_never_replaced(): void
    {
        $this->connect(['repository_id' => FakeGitHub::REPOSITORY_ID, 'branch' => 'gone'])->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_BRANCH_NOT_FOUND');
        $this->assertSame(0, GitHubConnection::query()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeBranches(): array
    {
        return [
            'shell substitution' => ['$(id)'],
            'command chain' => ['main; rm -rf /'],
            'option injection' => ['-x'],
            'traversal' => ['../main'],
            'double dot' => ['a..b'],
            'reflog' => ['main@{1}'],
            'lock' => ['main.lock'],
            'space' => ['my branch'],
            'newline' => ["main\nx"],
            'query' => ['main?x=1'],
            'url' => ['https://evil.test/x'],
            'leading slash' => ['/main'],
            'trailing slash' => ['main/'],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    #[DataProvider('unsafeBranches')]
    public function test_unsafe_branch_names_never_reach_github(string $branch): void
    {
        $this->connect(['repository_id' => FakeGitHub::REPOSITORY_ID, 'branch' => $branch])->assertUnprocessable();

        $this->assertSame([], $this->github->requests);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function clientFacts(): array
    {
        return [
            'owner' => [['owner' => 'someone-else']],
            'name' => [['name' => 'other-repo']],
            'full name' => [['full_name' => 'someone-else/other-repo']],
            'installation' => [['installation_id' => 1]],
            'commit' => [['commit_sha' => str_repeat('a', 40)]],
            'archive url' => [['archive_url' => 'https://evil.test/a.zip']],
            'download url' => [['download_url' => 'http://169.254.169.254/latest']],
            'api url' => [['api_url' => 'https://evil.test']],
            'token' => [['token' => 'ghs_x']],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('clientFacts')]
    public function test_repository_facts_from_the_client_are_refused(array $extra): void
    {
        $this->connect(['repository_id' => FakeGitHub::REPOSITORY_ID] + $extra)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame([], $this->github->requests);
        $this->assertSame(0, GitHubConnection::query()->count());
    }

    public function test_the_repository_id_must_be_a_positive_integer(): void
    {
        foreach ([null, 0, -1, 'octo-org/billing-service', '1296269abc', 1.5, ['x']] as $value) {
            $this->connect(['repository_id' => $value])->assertUnprocessable();
        }
        $this->assertSame([], $this->github->requests);
    }

    public function test_one_connection_per_project(): void
    {
        $this->connect()->assertCreated();

        $this->connect()->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_ALREADY_CONNECTED');
        $this->assertSame(1, GitHubConnection::query()->count());
    }

    public function test_connecting_needs_a_github_authorization(): void
    {
        $other = User::factory()->create();
        $project = Project::factory()->for($other)->create();

        $this->connect(as: $other, project: $project)->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_AUTH_REQUIRED');
    }

    public function test_the_branch_can_be_changed_after_verification(): void
    {
        $this->connect()->assertCreated();
        $this->github->branches[FakeGitHub::REPOSITORY_ID]['develop'] = str_repeat('c', 40);
        // A renamed repository keeps its ID; the stored name follows GitHub.
        $this->github->repositories[FakeGitHub::REPOSITORY_ID]['name'] = 'billing';
        $this->github->repositories[FakeGitHub::REPOSITORY_ID]['full_name'] = 'octo-org/billing';

        $this->asUser($this->owner)->patchJson($this->path(), ['branch' => 'develop'])->assertOk()
            ->assertJsonPath('data.branch', 'develop')->assertJsonPath('data.repository.full_name', 'octo-org/billing');

        $this->asUser($this->owner)->patchJson($this->path(), ['branch' => 'nope'])->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_BRANCH_NOT_FOUND');
        $this->asUser($this->owner)->patchJson($this->path(), ['branch' => '$(id)'])->assertUnprocessable();
        $this->asUser($this->owner)->patchJson($this->path(), ['branch' => 'main', 'repository_id' => 1])->assertUnprocessable();
        $this->assertSame('develop', GitHubConnection::query()->sole()->branch);
    }

    public function test_branches_are_listed_from_github_bounded(): void
    {
        $this->connect()->assertCreated();
        $this->github->branches[FakeGitHub::REPOSITORY_ID] += ['develop' => str_repeat('c', 40), 'bad branch' => str_repeat('d', 40)];

        $response = $this->asUser($this->owner)->getJson($this->path('/branches'))->assertOk();

        $this->assertSame([['name' => 'main', 'protected' => true], ['name' => 'develop', 'protected' => false]], $response->json('data'));
        $response->assertJsonPath('meta.default_branch', 'main')->assertJsonPath('meta.has_more', false);
        $this->assertStringContainsString('per_page=50', $this->github->sent('GET', '#/branches$#')[0]->url());
        $this->asUser($this->owner)->getJson($this->path('/branches?per_page=1000'))->assertUnprocessable();
    }

    public function test_disconnecting_ends_access_only(): void
    {
        $this->connect()->assertCreated();

        $this->asUser($this->owner)->deleteJson($this->path())->assertOk()->assertJsonPath('data.status', 'DISCONNECTED');

        $this->asUser($this->owner)->getJson($this->path())->assertJsonPath('data.connection', null);
        $this->asUser($this->owner)->deleteJson($this->path())->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_NOT_CONNECTED');
        $this->asUser($this->owner)->patchJson($this->path(), ['branch' => 'main'])->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_NOT_CONNECTED');
        $this->asUser($this->owner)->getJson($this->path('/branches'))->assertStatus(409);
        // The disconnected row stays as provenance, and a new connection can be made.
        $this->assertSame(1, GitHubConnection::query()->count());
        $this->connect()->assertCreated();
    }

    public function test_archived_projects_are_readable_and_can_only_be_disconnected(): void
    {
        $this->connect()->assertCreated();
        $this->project->archive();

        $this->asUser($this->owner)->getJson($this->path())->assertOk()->assertJsonPath('data.connection.status', 'ACTIVE');
        $this->asUser($this->owner)->patchJson($this->path(), ['branch' => 'main'])->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        $this->asUser($this->owner)->postJson($this->path('/imports'))->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        $this->asUser($this->owner)->deleteJson($this->path())->assertOk();
        $this->connect()->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
    }

    public function test_authentication_is_required(): void
    {
        foreach (['getJson' => '', 'postJson' => '', 'patchJson' => '', 'deleteJson' => ''] as $method => $suffix) {
            $this->{$method}($this->path($suffix))->assertUnauthorized();
        }
        $this->getJson($this->path('/branches'))->assertUnauthorized();
        $this->postJson($this->path('/imports'))->assertUnauthorized();
        $this->assertSame([], $this->github->requests);
    }

    public function test_only_the_owner_can_see_or_change_a_connection(): void
    {
        $this->connect()->assertCreated();
        $stranger = User::factory()->create();
        GitHubFixtures::account($stranger);
        $before = count($this->github->requests);

        $this->asUser($stranger)->getJson($this->path())->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->connect(as: $stranger)->assertNotFound();
        $this->asUser($stranger)->patchJson($this->path(), ['branch' => 'main'])->assertNotFound();
        $this->asUser($stranger)->deleteJson($this->path())->assertNotFound();
        $this->asUser($stranger)->getJson($this->path('/branches'))->assertNotFound();
        $this->asUser($stranger)->postJson($this->path('/imports'))->assertNotFound();
        $this->asUser($stranger)->getJson($this->path('/imports'))->assertNotFound();

        $this->assertSame($before, count($this->github->requests), 'nothing reaches GitHub for a stranger');
        $this->assertSame('ACTIVE', GitHubConnection::query()->sole()->status->value);
    }
}
