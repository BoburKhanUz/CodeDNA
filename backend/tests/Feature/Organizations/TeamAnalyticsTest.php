<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\AssessmentFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24: team analytics read the organization's own snapshots, never
 * write, never mix versions and never average thin evidence
 * (docs/teams/team-analytics.md).
 */
final class TeamAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = OrganizationFixtures::create($this->owner);
    }

    private function teamProject(?User $creator = null): Project
    {
        $creator ??= OrganizationFixtures::member($this->organization)->user;
        $project = OrganizationFixtures::project($this->organization, $creator);
        AssessmentFixtures::skillGaps($project);

        return $project;
    }

    /** @return array<string, mixed> */
    private function analytics(?User $as = null): array
    {
        return $this->asUser($as ?? $this->owner)->getJson("/api/v1/organizations/{$this->organization->id}/analytics")->assertOk()->json('data');
    }

    public function test_figures_come_from_the_latest_snapshot_of_each_active_team_project(): void
    {
        $projects = [$this->teamProject(), $this->teamProject(), $this->teamProject()];
        $archived = $this->teamProject();
        DB::table('projects')->where('id', $archived->id)->update(['status' => 'ARCHIVED']);
        // A member's personal project is never part of team analytics.
        $member = OrganizationFixtures::membershipOf($this->organization, User::query()->findOrFail($projects[0]->user_id))->user;
        AssessmentFixtures::skillGaps(Project::factory()->for($member)->create());

        $data = $this->analytics();

        $this->assertSame(['active' => 3, 'archived' => 1], $data['projects']);
        $this->assertSame(['active' => 5, 'suspended' => 0, 'by_role' => ['OWNER' => 1, 'ADMIN' => 0, 'MEMBER' => 4]], $data['members']);
        $this->assertSame(4, $data['analyses']['succeeded'], 'analysis activity counts archived projects too');
        $this->assertSame(3, $data['dna']['projects_with_dna']);
        $this->assertSame(3, $data['dna']['members_with_dna']);
        $expected = (float) DB::table('dna_snapshots')->whereIn('project_id', array_map(fn (Project $p) => $p->id, $projects))->avg('overall_score');
        $this->assertSame([['scoring_version' => '1.0.0', 'projects' => 3, 'scored_projects' => 3, 'sufficient' => true, 'average_overall_score' => round($expected, 4)]],
            $data['dna']['by_version']);
        $this->assertCount(1, $data['competencies']);
        $group = $data['competencies'][0];
        $this->assertSame(3, $group['projects']);
        $this->assertSame(3, array_sum($group['snapshot_status']));
        foreach ($group['competencies'] as $competency) {
            $this->assertSame($competency['measured_projects'] >= 2, $competency['sufficient']);
            $this->assertSame($competency['sufficient'], $competency['average_score'] !== null);
        }
    }

    public function test_thin_evidence_gives_no_average(): void
    {
        $this->teamProject();

        $data = $this->analytics();

        $this->assertSame(false, $data['dna']['by_version'][0]['sufficient']);
        $this->assertNull($data['dna']['by_version'][0]['average_overall_score']);
        $this->assertSame([null], array_values(array_unique(array_column($data['competencies'][0]['competencies'], 'average_score'))));
        $this->assertSame(2, $data['minimum_projects']);
    }

    public function test_different_versions_are_reported_side_by_side_never_averaged_together(): void
    {
        $this->teamProject();
        $this->teamProject();
        $moved = $this->teamProject();
        // The third project's latest analysis was made with another skill gap version.
        $latest = SkillGapSnapshot::query()->where('project_id', $moved->id)->sole();
        $id = strtolower((string) Str::ulid());
        DB::statement(<<<'SQL'
            INSERT INTO skill_gap_snapshots (id, user_id, project_id, competency_snapshot_id, dna_snapshot_id, analysis_run_id, source_snapshot_id,
                skill_gap_version, target_profile, target_profile_version, competency_version, dna_scoring_version, specification_fingerprint, status, summary, provenance, created_at)
            SELECT ?, user_id, project_id, competency_snapshot_id, dna_snapshot_id, analysis_run_id, source_snapshot_id,
                '9.0.0', target_profile, target_profile_version, competency_version, dna_scoring_version, specification_fingerprint, status, summary, provenance, created_at + interval '1 hour'
            FROM skill_gap_snapshots WHERE id = ?
        SQL, [$id, $latest->id]);
        foreach (DB::table('skill_gap_results')->where('skill_gap_snapshot_id', $latest->id)->get() as $row) {
            DB::table('skill_gap_results')->insert([...(array) $row, 'id' => strtolower((string) Str::ulid()), 'skill_gap_snapshot_id' => $id]);
        }

        $groups = $this->analytics()['competencies'];

        $this->assertSame([[2, '1.0.0'], [1, '9.0.0']], array_map(fn ($g) => [$g['projects'], $g['skill_gap_version']], $groups));
        $this->assertSame([null], array_values(array_unique(array_column($groups[1]['competencies'], 'average_score'))), 'one project is not enough');
    }

    public function test_members_with_dna_are_current_members_only(): void
    {
        $removed = OrganizationFixtures::member($this->organization)->user;
        $this->teamProject($removed);
        $this->teamProject();
        OrganizationFixtures::membershipOf($this->organization, $removed)->forceFill(['status' => MembershipStatus::Removed])->save();

        $data = $this->analytics();

        $this->assertSame([2, 1], [$data['dna']['projects_with_dna'], $data['dna']['members_with_dna']]);
    }

    public function test_analytics_never_write_and_use_a_bounded_number_of_queries(): void
    {
        $this->teamProject();
        $this->teamProject();
        $counts = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(),
            ['dna_snapshots', 'competency_snapshots', 'skill_gap_snapshots', 'skill_gap_results', 'growth_snapshots', 'analysis_runs']);
        $before = $counts();
        $statements = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$statements): void {
            $statements[] = $q->sql;
        });

        $this->analytics();
        $small = count($statements);
        $this->assertSame($before, $counts(), 'no snapshot is created or removed');
        $this->assertSame([], array_values(array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1
            && ! str_contains($sql, 'sessions'))), 'analytics write nothing');

        $this->teamProject();
        $this->teamProject();
        $this->teamProject();
        $statements = [];
        $this->analytics();

        $this->assertSame($small, count($statements), 'the query count does not grow with the number of projects');
    }

    public function test_analytics_are_for_members(): void
    {
        $member = OrganizationFixtures::member($this->organization)->user;
        $this->analytics($member);
        $this->asUser(User::factory()->create())->getJson("/api/v1/organizations/{$this->organization->id}/analytics")->assertNotFound();
    }
}
