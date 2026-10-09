<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Enums\GitHub\GitHubImportStatus;
use App\Enums\Organizations\MembershipStatus;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\GitHubImport;
use App\Models\GrowthSnapshot;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\CrossUserAccessTest;
use Tests\Support\AssessmentFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\Support\GitHubFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24 security regression: every project-scoped GET route, for a team
 * project. Members read everything; a non-member, a member of another
 * organization, a removed member and even the project's own creator once
 * removed get the same 404 as for a project that does not exist; a
 * suspended member gets 403 MEMBERSHIP_SUSPENDED. Nested resources are
 * never reachable through another organization's project.
 */
final class TeamProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $creator;

    private Project $project;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => false, 'codedna.challenges.enabled' => true]);
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator('pass'));
        $this->organization = OrganizationFixtures::create(User::factory()->create());
        $this->creator = OrganizationFixtures::member($this->organization)->user;
        $this->project = OrganizationFixtures::project($this->organization, $this->creator);

        $gaps = AssessmentFixtures::skillGaps($this->project);
        app(CalculateGrowthSnapshot::class)->handle($gaps->id);
        $base = "/api/v1/projects/{$this->project->id}";
        $challenge = $this->asUser($this->creator)->postJson("{$base}/challenges", ['skill_gap_snapshot_id' => $gaps->id])->assertCreated()->json('data.id');
        $submission = $this->asUser($this->creator)->postJson("{$base}/challenges/{$challenge}/submissions", ['language' => 'python', 'source' => "def f():\n    return 1\n"])->json('data.id');
        app()->call([new EvaluateChallengeSubmission($submission), 'handle']);
        $roadmap = $this->asUser($this->creator)->postJson("{$base}/roadmaps", [])->assertCreated()->json('data.id');
        $connection = GitHubFixtures::connection($this->project);
        $import = new GitHubImport;
        $import->forceFill([
            'github_connection_id' => $connection->id, 'project_id' => $this->project->id, 'user_id' => $this->creator->id,
            'repository_id' => $connection->repository_id, 'repository_full_name' => $connection->repository_full_name,
            'ref' => 'main', 'status' => GitHubImportStatus::Queued,
        ])->save();
        $this->ids = [
            'sourceSnapshot' => $gaps->source_snapshot_id, 'analysisRun' => $gaps->analysis_run_id, 'dnaSnapshot' => $gaps->dna_snapshot_id,
            'competencySnapshot' => $gaps->competency_snapshot_id, 'skillGapSnapshot' => $gaps->id, 'growthSnapshot' => GrowthSnapshot::query()->sole()->id,
            'challengeInstance' => $challenge, 'challengeSubmission' => $submission, 'roadmapSnapshot' => $roadmap, 'githubImport' => $import->id,
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function routes(): array
    {
        // Team plans do not include AI (FEATURE_NOT_INCLUDED), so a team project
        // never has AI assessments or insights to read: those routes are covered
        // by CrossUserAccessTest for personal projects.
        return array_filter(CrossUserAccessTest::routes(), fn (string $route): bool => ! str_contains($route, '{ai') && ! str_contains($route, '/insights'), ARRAY_FILTER_USE_KEY);
    }

    private function url(string $route, ?Project $project = null): string
    {
        $path = str_replace('{project}', ($project ?? $this->project)->id, $route);
        foreach ($this->ids as $name => $id) {
            $path = str_replace('{'.$name.'}', $id, $path);
        }

        return '/api/v1/projects/'.$path;
    }

    #[DataProvider('routes')]
    public function test_any_active_member_reads_it(string $route): void
    {
        $this->asUser(OrganizationFixtures::member($this->organization)->user)->getJson($this->url($route))->assertOk();
    }

    #[DataProvider('routes')]
    public function test_outsiders_get_not_found_and_suspended_members_forbidden(string $route): void
    {
        $other = OrganizationFixtures::create(User::factory()->create(), 'Other');
        $outsiders = [
            'stranger' => User::factory()->create(),
            'member of another organization' => OrganizationFixtures::member($other)->user,
            'removed member' => OrganizationFixtures::member($this->organization, status: MembershipStatus::Removed)->user,
        ];
        foreach ($outsiders as $who => $user) {
            foreach (['', '?per_page=1000&page=0'] as $query) {
                $this->asUser($user)->getJson($this->url($route).$query)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
            }
        }
        $suspended = OrganizationFixtures::member($this->organization, status: MembershipStatus::Suspended)->user;
        $this->asUser($suspended)->getJson($this->url($route))->assertForbidden()->assertJsonPath('error.code', 'MEMBERSHIP_SUSPENDED');
    }

    public function test_the_creator_loses_access_with_their_membership(): void
    {
        $this->asUser($this->creator)->getJson($this->url('{project}'))->assertOk();
        OrganizationFixtures::membershipOf($this->organization, $this->creator)->forceFill(['status' => MembershipStatus::Removed])->save();

        $this->asUser($this->creator)->getJson($this->url('{project}'))->assertNotFound();
        $this->asUser($this->creator)->getJson('/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[DataProvider('routes')]
    public function test_nested_resources_are_not_reachable_through_another_organizations_project(string $route): void
    {
        if (substr_count($route, '{') < 2) {
            $this->assertTrue(true, 'no nested resource');

            return;
        }
        $other = OrganizationFixtures::create($owner = User::factory()->create(), 'Other');
        $theirs = OrganizationFixtures::project($other, $owner);
        $this->asUser($owner)->getJson($this->url($route, $theirs))->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }
}
