<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Services\Billing\QuotaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Team analytics (docs/teams/team-analytics.md): a read model over the
 * organization's own projects. It never writes and never scores: DNA,
 * competency and skill gap snapshots are read as they were stored, and no
 * individual snapshot is changed, recalculated or created. Personal
 * projects of members are never read.
 *
 * Semantics:
 * - only the organization's ACTIVE projects, each by its latest snapshot;
 * - results are grouped by the versions that produced them (scoring
 *   version; competency, skill gap and target profile versions) and never
 *   averaged across versions;
 * - an average is given only when at least MINIMUM_PROJECTS projects have
 *   measured evidence for it; otherwise it is null and marked insufficient.
 *
 * Every figure is one SQL aggregate: the query count does not grow with the
 * organization's history, and no snapshot is loaded into memory.
 *
 * Performance (Phase 26, docs/performance/database-performance.md#team-analytics):
 * the latest snapshot of each active project is found with one index probe
 * per project (LATERAL … ORDER BY created_at DESC, id DESC LIMIT 1 on the
 * (project_id, created_at) index), never by sorting every snapshot of the
 * table, so the cost follows the organization's size, not the platform's.
 * Each "latest" set is computed once per request (a MATERIALIZED CTE shared
 * by the figures that need it).
 */
final readonly class TeamAnalytics
{
    public const MINIMUM_PROJECTS = 2;

    private const MEASURED = "('GAP', 'NO_GAP')";

    public function __construct(private QuotaService $quotas) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization): array
    {
        $id = (string) $organization->getKey();

        return [
            'type' => 'organization_analytics',
            'organization_id' => $id,
            'generated_at' => Carbon::now()->toIso8601ZuluString(),
            'minimum_projects' => self::MINIMUM_PROJECTS,
            'members' => $this->members($id),
            'seats' => $this->quotas->seats($organization),
            'projects' => $this->projects($id),
            'analyses' => $this->analyses($id),
            'dna' => $this->dna($id),
            'competencies' => $this->competencies($id),
        ];
    }

    /**
     * @return array{active: int, suspended: int, by_role: array<string, int>}
     */
    private function members(string $id): array
    {
        $rows = DB::select('SELECT role, status, count(*) AS n FROM organization_memberships WHERE organization_id = ? GROUP BY role, status', [$id]);
        $byRole = ['OWNER' => 0, 'ADMIN' => 0, 'MEMBER' => 0];
        $active = $suspended = 0;
        foreach ($rows as $row) {
            if ($row->status === 'ACTIVE') {
                $active += (int) $row->n;
                $byRole[$row->role] += (int) $row->n;
            } elseif ($row->status === 'SUSPENDED') {
                $suspended += (int) $row->n;
            }
        }

        return ['active' => $active, 'suspended' => $suspended, 'by_role' => $byRole];
    }

    /**
     * @return array{active: int, archived: int}
     */
    private function projects(string $id): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE status = 'ACTIVE') AS active, count(*) FILTER (WHERE status = 'ARCHIVED') AS archived
            FROM projects WHERE organization_id = ?
        SQL, [$id]);

        return ['active' => (int) $row->active, 'archived' => (int) $row->archived];
    }

    /**
     * @return array{succeeded: int, failed: int, succeeded_last_30_days: int, last_completed_at: string|null}
     */
    private function analyses(string $id): array
    {
        // Per project through its (project_id, created_at) index: the runs of
        // other organizations are never read.
        $row = DB::selectOne(<<<'SQL'
            SELECT coalesce(sum(a.succeeded), 0) AS succeeded, coalesce(sum(a.failed), 0) AS failed,
                   coalesce(sum(a.recent), 0) AS recent, max(a.last_completed_at) AS last_completed_at
            FROM projects p CROSS JOIN LATERAL (
                SELECT count(*) FILTER (WHERE r.status = 'SUCCEEDED') AS succeeded,
                       count(*) FILTER (WHERE r.status = 'FAILED') AS failed,
                       count(*) FILTER (WHERE r.status = 'SUCCEEDED' AND r.completed_at >= ?) AS recent,
                       max(r.completed_at) FILTER (WHERE r.status = 'SUCCEEDED') AS last_completed_at
                FROM analysis_runs r WHERE r.project_id = p.id
            ) a
            WHERE p.organization_id = ?
        SQL, [Carbon::now()->subDays(30), $id]);

        return [
            'succeeded' => (int) $row->succeeded,
            'failed' => (int) $row->failed,
            'succeeded_last_30_days' => (int) $row->recent,
            'last_completed_at' => $row->last_completed_at === null ? null : Carbon::parse($row->last_completed_at)->toIso8601ZuluString(),
        ];
    }

    /**
     * Latest DNA snapshot of each active project, grouped by scoring version.
     *
     * @return array<string, mixed>
     */
    private function dna(string $id): array
    {
        // One query: the latest DNA of each active project (one index probe
        // per project), its groups, and the ACTIVE members who created a team
        // project that has a DNA snapshot.
        $groups = DB::select(<<<'SQL'
            WITH latest AS MATERIALIZED (
                SELECT d.user_id, d.scoring_version, d.overall_score
                FROM projects p CROSS JOIN LATERAL (
                    SELECT d.user_id, d.scoring_version, d.overall_score FROM dna_snapshots d
                    WHERE d.project_id = p.id ORDER BY d.created_at DESC, d.id DESC LIMIT 1
                ) d
                WHERE p.organization_id = ? AND p.status = 'ACTIVE'
            )
            SELECT scoring_version, count(*) AS projects, count(overall_score) AS scored, avg(overall_score) AS average,
                   (SELECT count(DISTINCT l.user_id) FROM latest l
                    JOIN organization_memberships m ON m.user_id = l.user_id AND m.organization_id = ? AND m.status = 'ACTIVE') AS members
            FROM latest GROUP BY scoring_version ORDER BY scoring_version
        SQL, [$id, $id]);
        $membersWithDna = $groups === [] ? 0 : (int) $groups[0]->members;

        return [
            'projects_with_dna' => array_sum(array_map(fn (object $g): int => (int) $g->projects, $groups)),
            'members_with_dna' => $membersWithDna,
            'by_version' => array_map(fn (object $g): array => [
                'scoring_version' => $g->scoring_version,
                'projects' => (int) $g->projects,
                'scored_projects' => (int) $g->scored,
                'sufficient' => (int) $g->scored >= self::MINIMUM_PROJECTS,
                'average_overall_score' => (int) $g->scored >= self::MINIMUM_PROJECTS ? round((float) $g->average, 4) : null,
            ], $groups),
        ];
    }

    /**
     * Latest skill gap analysis of each active project, grouped by the
     * versions that produced it, with per-competency figures.
     *
     * @return list<array<string, mixed>>
     */
    private function competencies(string $id): array
    {
        // The latest skill gap snapshot of each active project (one index
        // probe per project), shared by both aggregates of one query each.
        $latest = <<<'SQL'
            WITH latest AS MATERIALIZED (
                SELECT s.id, s.status, s.competency_version, s.skill_gap_version, s.target_profile, s.target_profile_version
                FROM projects p CROSS JOIN LATERAL (
                    SELECT s.id, s.status, s.competency_version, s.skill_gap_version, s.target_profile, s.target_profile_version
                    FROM skill_gap_snapshots s WHERE s.project_id = p.id ORDER BY s.created_at DESC, s.id DESC LIMIT 1
                ) s
                WHERE p.organization_id = ? AND p.status = 'ACTIVE'
            )
        SQL;
        $measured = self::MEASURED;
        $snapshots = DB::select(<<<SQL
            {$latest}
            SELECT competency_version, skill_gap_version, target_profile, target_profile_version, status, count(*) AS n
            FROM latest
            GROUP BY 1, 2, 3, 4, 5
        SQL, [$id]);
        $rows = DB::select(<<<SQL
            {$latest}
            SELECT l.competency_version, l.skill_gap_version, l.target_profile, l.target_profile_version, r.competency_key,
                   count(*) FILTER (WHERE r.status IN {$measured}) AS measured,
                   avg(r.current_score) FILTER (WHERE r.status IN {$measured}) AS average,
                   count(*) FILTER (WHERE r.status = 'GAP') AS gaps,
                   count(*) FILTER (WHERE r.priority = 'HIGH') AS high,
                   count(*) FILTER (WHERE r.priority = 'MEDIUM') AS medium,
                   count(*) FILTER (WHERE r.priority = 'LOW') AS low,
                   count(*) FILTER (WHERE r.status IN ('INSUFFICIENT_EVIDENCE', 'MISSING', 'UNSUPPORTED')) AS insufficient
            FROM latest l JOIN skill_gap_results r ON r.skill_gap_snapshot_id = l.id
            GROUP BY 1, 2, 3, 4, 5
            ORDER BY 1, 2, 3, 4, min(r.position), 5
        SQL, [$id]);

        $groups = [];
        $key = static fn (object $r): string => implode('|', [$r->competency_version, $r->skill_gap_version, $r->target_profile, $r->target_profile_version]);
        foreach ($snapshots as $s) {
            $groups[$key($s)] ??= [
                'competency_version' => $s->competency_version,
                'skill_gap_version' => $s->skill_gap_version,
                'target_profile' => $s->target_profile,
                'target_profile_version' => $s->target_profile_version,
                'projects' => 0,
                'snapshot_status' => ['GAPS_IDENTIFIED' => 0, 'NO_MATERIAL_GAPS' => 0, 'INSUFFICIENT_DATA' => 0],
                'competencies' => [],
            ];
            $groups[$key($s)]['projects'] += (int) $s->n;
            $groups[$key($s)]['snapshot_status'][$s->status] = (int) $s->n;
        }
        foreach ($rows as $r) {
            $sufficient = (int) $r->measured >= self::MINIMUM_PROJECTS;
            $groups[$key($r)]['competencies'][] = [
                'key' => $r->competency_key,
                'measured_projects' => (int) $r->measured,
                'sufficient' => $sufficient,
                'average_score' => $sufficient ? round((float) $r->average, 4) : null,
                'gap_projects' => (int) $r->gaps,
                'priorities' => ['HIGH' => (int) $r->high, 'MEDIUM' => (int) $r->medium, 'LOW' => (int) $r->low],
                'insufficient_evidence_projects' => (int) $r->insufficient,
            ];
        }
        ksort($groups);

        return array_values($groups);
    }
}
