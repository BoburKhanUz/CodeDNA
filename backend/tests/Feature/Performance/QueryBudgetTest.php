<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Actions\Roadmap\GenerateRoadmap;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssessmentFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Query-count and response-size regression (Phase 26,
 * docs/performance/performance-architecture.md#regression-protection).
 *
 * Each endpoint is measured twice: with two assessments (or members, or
 * events) and again after more are added. The number of queries must not
 * grow with the data (no N+1, no per-row authorization or quota lookups),
 * and stays under a generous ceiling; list items stay under a size budget.
 * Exact counts are deliberately not pinned, so harmless framework changes
 * do not fail these tests.
 */
final class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const CEILING = 14;

    private User $owner;

    private Project $project;

    /** @var list<string> */
    private array $sql = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
        DB::listen(function (QueryExecuted $query): void {
            $this->sql[] = $query->sql;
        });
    }

    private function assess(Project $project, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $gaps = AssessmentFixtures::skillGaps($project);
            app(CalculateGrowthSnapshot::class)->handle($gaps->id);
        }
    }

    /** @return array{0: int, 1: TestResponse<JsonResponse>} */
    private function measure(User $user, string $uri): array
    {
        $this->app['auth']->forgetGuards();
        $this->sql = [];
        $response = $this->asUser($user)->getJson($uri)->assertOk();

        return [count($this->sql), $response];
    }

    /**
     * @param  array<string, Closure(): string>  $endpoints  name => URI (built after the data exists)
     * @param  Closure(): void  $grow  adds more of the data the endpoints list
     * @param  array<string, int>  $bytesPerItem  list endpoint => size budget per item
     */
    private function assertBounded(User $user, array $endpoints, Closure $grow, array $bytesPerItem = []): void
    {
        $before = [];
        foreach ($endpoints as $name => $uri) {
            [$before[$name]] = $this->measure($user, $uri());
        }
        $grow();
        foreach ($endpoints as $name => $uri) {
            [$after, $response] = $this->measure($user, $uri());
            $this->assertSame($before[$name], $after, "{$name}: the query count grew with the data (N+1?): ".implode(' | ', $this->sql));
            $this->assertLessThanOrEqual(self::CEILING, $after, "{$name}: {$after} queries");
            if (isset($bytesPerItem[$name])) {
                $items = max(1, count((array) $response->json('data')));
                $this->assertLessThanOrEqual($bytesPerItem[$name], intdiv(strlen((string) $response->getContent()), $items), "{$name}: response size per item");
            }
        }
    }

    public function test_project_reads_do_not_grow_with_the_history(): void
    {
        $this->assess($this->project, 2);
        app(GenerateRoadmap::class)->handle($this->project, $this->owner);
        $p = "/api/v1/projects/{$this->project->id}";
        $latest = fn (string $table): string => (string) DB::table($table)->where('project_id', $this->project->id)->orderByDesc('created_at')->orderByDesc('id')->value('id');
        $oldest = fn (string $table): string => (string) DB::table($table)->where('project_id', $this->project->id)->orderBy('created_at')->orderBy('id')->value('id');

        $this->assertBounded($this->owner, [
            'projects' => fn () => '/api/v1/projects',
            'project' => fn () => $p,
            'source snapshots' => fn () => "{$p}/source-snapshots",
            'source snapshots (cursor)' => fn () => "{$p}/source-snapshots?cursor=",
            'analyses' => fn () => "{$p}/analyses",
            'analyses (cursor)' => fn () => "{$p}/analyses?cursor=",
            'dna' => fn () => "{$p}/dna",
            'dna detail' => fn () => "{$p}/dna/".$latest('dna_snapshots'),
            'history' => fn () => "{$p}/history",
            'history (cursor)' => fn () => "{$p}/history?cursor=",
            'history point' => fn () => "{$p}/history/".$latest('dna_snapshots'),
            'history comparison' => fn () => "{$p}/history/compare?from=".$oldest('dna_snapshots').'&to='.$latest('dna_snapshots'),
            'competencies' => fn () => "{$p}/competencies",
            'skill gaps' => fn () => "{$p}/skill-gaps",
            'skill gap detail' => fn () => "{$p}/skill-gaps/".$latest('skill_gap_snapshots'),
            'growth' => fn () => "{$p}/growth",
            'growth timeline' => fn () => "{$p}/growth/timeline",
            'growth timeline (cursor)' => fn () => "{$p}/growth/timeline?cursor=",
            'roadmaps' => fn () => "{$p}/roadmaps",
            'roadmap' => fn () => "{$p}/roadmaps/".$latest('roadmap_snapshots'),
            'github' => fn () => "{$p}/github",
            'github imports' => fn () => "{$p}/github/imports",
            'billing' => fn () => '/api/v1/billing',
            'billing usage' => fn () => '/api/v1/billing/usage',
            'billing usage (cursor)' => fn () => '/api/v1/billing/usage?cursor=',
        ], fn () => $this->assess($this->project, 4), [
            // Each history point carries its DNA, competency, skill gap and
            // growth layers; nothing else (no analyzer payloads, no relations).
            'history' => 8_192, 'history (cursor)' => 8_192,
            'analyses' => 1_024, 'analyses (cursor)' => 1_024,
            'source snapshots' => 1_024, 'dna' => 1_024, 'competencies' => 2_048, 'skill gaps' => 2_048,
            'growth timeline' => 4_096,
        ]);
    }

    public function test_organization_reads_do_not_grow_with_members_projects_or_events(): void
    {
        $organization = OrganizationFixtures::create($this->owner);
        $grow = function () use ($organization): void {
            for ($i = 0; $i < 3; $i++) {
                $member = OrganizationFixtures::member($organization, role: OrganizationRole::Admin)->user;
                $this->assess(OrganizationFixtures::project($organization, $member), 1);
            }
        };
        $grow();
        $o = "/api/v1/organizations/{$organization->id}";

        $this->assertBounded($this->owner, [
            'organizations' => fn () => '/api/v1/organizations',
            'organization' => fn () => $o,
            'members' => fn () => "{$o}/members",
            'organization projects' => fn () => "{$o}/projects",
            'audit events' => fn () => "{$o}/audit-events",
            'analytics' => fn () => "{$o}/analytics",
            'organization billing' => fn () => "{$o}/billing",
            'invitations' => fn () => "{$o}/invitations",
        ], $grow, ['audit events' => 1_024, 'members' => 1_024, 'organization projects' => 1_024]);
    }

    public function test_a_team_project_checks_the_callers_membership_once_per_request(): void
    {
        $organization = OrganizationFixtures::create($this->owner);
        $member = OrganizationFixtures::member($organization)->user;
        $project = OrganizationFixtures::project($organization, $this->owner);
        $this->assess($project, 1);

        // The form request and the controller both authorize the view; the
        // second decision is the request's remembered ALLOW (ViewDecisions).
        $this->measure($member, "/api/v1/projects/{$project->id}/dna");
        $membership = array_filter($this->sql, fn (string $sql): bool => str_contains($sql, 'from "organization_memberships"'));
        $this->assertCount(1, $membership, implode(' | ', $this->sql));
    }

    public function test_the_quota_summary_reads_every_monthly_counter_in_one_query(): void
    {
        $this->measure($this->owner, '/api/v1/billing');
        $counters = array_filter($this->sql, fn (string $sql): bool => str_contains($sql, 'billing_usage_counters'));
        $this->assertCount(1, $counters, implode(' | ', $this->sql));
    }

    public function test_the_organization_billing_account_is_read_twice_at_most(): void
    {
        $organization = OrganizationFixtures::create($this->owner);
        $this->measure($this->owner, "/api/v1/organizations/{$organization->id}/billing");
        $accounts = array_filter($this->sql, fn (string $sql): bool => str_contains($sql, 'from "organization_billing_accounts"'));
        // Once to resolve the plan, once for the account's own fields (was three times).
        $this->assertLessThanOrEqual(2, count($accounts), implode(' | ', $this->sql));
        $this->assertInstanceOf(Organization::class, $organization);
    }
}
