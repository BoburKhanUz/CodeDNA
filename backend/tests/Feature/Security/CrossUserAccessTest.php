<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Enums\GitHub\GitHubImportStatus;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\AnalysisRun;
use App\Models\GitHubImport;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\User;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssessmentFixtures;
use Tests\Support\BillingFixtures;
use Tests\Support\FakeChallengeEvaluator;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

/**
 * Phase 21 security regression: one matrix over every project-scoped GET
 * route. Another user gets the same 404 RESOURCE_NOT_FOUND as for a project
 * that does not exist, with valid or invalid query strings alike (invalid
 * input must not answer 422 and so confirm that the project exists), and
 * nested resources are never reachable through another project.
 */
final class CrossUserAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    private Project $project;

    /** @var array<string, string> route parameter name => ID of the owner's resource */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => false, 'codedna.challenges.enabled' => true]);
        $this->app->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator('pass'));
        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();

        $gaps = AssessmentFixtures::skillGaps($this->project);
        app(CalculateGrowthSnapshot::class)->handle($gaps->id);
        $base = "/api/v1/projects/{$this->project->id}";
        $challenge = $this->asUser($this->owner)->postJson("{$base}/challenges", ['skill_gap_snapshot_id' => $gaps->id])->assertCreated()->json('data.id');
        $submission = $this->asUser($this->owner)->postJson("{$base}/challenges/{$challenge}/submissions", ['language' => 'python', 'source' => "def f():\n    return 1\n"])->json('data.id');
        app()->call([new EvaluateChallengeSubmission($submission), 'handle']);
        $roadmap = $this->asUser($this->owner)->postJson("{$base}/roadmaps", [])->assertCreated()->json('data.id');
        // Phase 30: an AI assessment and an AI insight (fake provider; jobs are queued, never run).
        config(['codedna.ai.enabled' => true, 'codedna.ai.provider' => 'fake']);
        BillingFixtures::pro($this->owner);
        $assessment = $this->asUser($this->owner)->postJson("{$base}/assessments", ['skill_gap_snapshot_id' => $gaps->id])->assertAccepted()->json('data.id');
        $insight = $this->asUser($this->owner)->postJson("{$base}/insights", ['kind' => 'ROADMAP_GUIDANCE', 'subject_id' => $roadmap])->assertAccepted()->json('data.id');
        config(['codedna.ai.enabled' => false]);
        $connection = GitHubFixtures::connection($this->project);
        $import = new GitHubImport;
        $import->forceFill([
            'github_connection_id' => $connection->id, 'project_id' => $this->project->id, 'user_id' => $this->owner->id,
            'repository_id' => $connection->repository_id, 'repository_full_name' => $connection->repository_full_name,
            'ref' => 'main', 'status' => GitHubImportStatus::Queued,
        ])->save();

        $this->ids = [
            'sourceSnapshot' => $gaps->source_snapshot_id,
            'analysisRun' => $gaps->analysis_run_id,
            'dnaSnapshot' => $gaps->dna_snapshot_id,
            'competencySnapshot' => $gaps->competency_snapshot_id,
            'skillGapSnapshot' => $gaps->id,
            'growthSnapshot' => GrowthSnapshot::query()->sole()->id,
            'challengeInstance' => $challenge,
            'challengeSubmission' => $submission,
            'roadmapSnapshot' => $roadmap,
            'githubImport' => $import->id,
            'aiAssessment' => $assessment,
            'aiInsight' => $insight,
        ];
        $this->assertSame(1, AnalysisRun::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function routes(): array
    {
        return array_combine($list = [
            '{project}',
            '{project}/source-snapshots', '{project}/source-snapshots/{sourceSnapshot}',
            '{project}/analyses', '{project}/analyses/{analysisRun}', '{project}/analyses/{analysisRun}/result',
            '{project}/dna', '{project}/dna/{dnaSnapshot}',
            '{project}/competencies', '{project}/competencies/{competencySnapshot}',
            '{project}/skill-gaps', '{project}/skill-gaps/{skillGapSnapshot}',
            '{project}/assessments', '{project}/assessments/{aiAssessment}',
            '{project}/insights?kind=ROADMAP_GUIDANCE&subject_id={roadmapSnapshot}', '{project}/insights/{aiInsight}',
            '{project}/challenges', '{project}/challenges/{challengeInstance}',
            '{project}/challenges/{challengeInstance}/submissions', '{project}/challenges/{challengeInstance}/submissions/{challengeSubmission}',
            '{project}/roadmaps', '{project}/roadmaps/{roadmapSnapshot}',
            '{project}/growth', '{project}/growth/timeline', '{project}/growth/{growthSnapshot}',
            '{project}/history', '{project}/history/{dnaSnapshot}',
            '{project}/github', '{project}/github/imports', '{project}/github/imports/{githubImport}',
        ], array_map(fn (string $r): array => [$r], $list));
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
    public function test_the_owner_can_read_it(string $route): void
    {
        $this->asUser($this->owner)->getJson($this->url($route))->assertOk();
    }

    #[DataProvider('routes')]
    public function test_another_user_gets_not_found_whatever_the_query(string $route): void
    {
        foreach (['', '?per_page=1000&page=0', '?from=x&to=y', '?status=NOPE'] as $query) {
            $this->asUser($this->stranger)->getJson($this->url($route).(str_contains($route, '?') ? str_replace('?', '&', $query) : $query))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        }
    }

    #[DataProvider('routes')]
    public function test_nested_resources_are_not_reachable_through_another_project(string $route): void
    {
        if (! str_contains($route, '}/') || substr_count($route, '{') < 2) {
            $this->assertTrue(true, 'no nested resource');

            return;
        }
        $mine = Project::factory()->for($this->stranger)->create();
        if (str_contains($route, '?')) {
            // A filter, not a nested path: the stranger's own list, which holds nothing of the owner's.
            $this->asUser($this->stranger)->getJson($this->url($route, $mine))->assertOk()->assertJsonCount(0, 'data');

            return;
        }
        $this->asUser($this->stranger)->getJson($this->url($route, $mine))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    /**
     * Routes the owner cannot read here without a live provider (they call
     * GitHub, GitLab or Bitbucket), so only the stranger's side is checked.
     *
     * @return array<string, array{string}>
     */
    public static function providerRoutes(): array
    {
        return array_combine($list = [
            '{project}/github/branches',
            '{project}/repository-provider', '{project}/repository-provider/branches',
            '{project}/repository-provider/imports', '{project}/repository-provider/imports/01m4abcdefghjkmnpqrstvwxyz',
        ], array_map(fn (string $r): array => [$r], $list));
    }

    #[DataProvider('providerRoutes')]
    public function test_another_user_gets_not_found_on_repository_provider_routes(string $route): void
    {
        foreach (['', '?per_page=1000&page=0&ref=x'] as $query) {
            $this->asUser($this->stranger)->getJson($this->url($route).$query)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        }
    }

    public function test_compare_with_another_users_project_is_not_found_before_validation(): void
    {
        $url = "/api/v1/projects/{$this->project->id}/history/compare";
        $this->asUser($this->stranger)->getJson($url)->assertNotFound();
        $this->asUser($this->owner)->getJson($url)->assertUnprocessable();
    }

    public function test_invalid_input_on_the_owners_own_project_is_still_validated(): void
    {
        $this->asUser($this->owner)->getJson($this->url('{project}/analyses').'?per_page=1000')->assertUnprocessable();
    }
}
