<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use App\Http\Pagination\CursorCodec;
use App\Http\Pagination\CursorDirection;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The endpoints benchmark:api measures, and whom it asks as. Subjects are
 * derived from BenchmarkScaler's deterministic layout, so the same scale
 * always measures the same rows. Iteration i picks a different user or
 * project each time (rotation by a prime stride); the outliers are fixed.
 */
final class BenchmarkEndpoints
{
    private int $users;

    private int $organizations;

    private int $projects;

    private int $analyses;

    private int $longHistory;

    private int $largeMembers;

    private int $largeProjects;

    private int $largeAudit;

    /** @var array<string, User> */
    private array $cache = [];

    public function __construct()
    {
        $this->users = (int) DB::table('users')->where('id', 'like', '010%')->count();
        $this->organizations = (int) DB::table('organizations')->where('id', 'like', '011%')->count() - 1;
        $this->projects = (int) DB::table('projects')->where('id', 'like', '020%')->count();
        $this->analyses = (int) DB::table('analysis_runs')->where('project_id', self::id(20, 1))->count();
        $this->longHistory = (int) DB::table('analysis_runs')->where('project_id', self::id(40, 1))->count();
        $largeOrg = self::id(11, $this->organizations + 1);
        $this->largeMembers = (int) DB::table('organization_memberships')->where('organization_id', $largeOrg)->count();
        $this->largeProjects = (int) DB::table('projects')->where('organization_id', $largeOrg)->count();
        $this->largeAudit = (int) DB::table('organization_audit_events')->where('organization_id', $largeOrg)->count();
    }

    public static function id(int $code, int $sequence): string
    {
        return '0'.str_pad((string) $code, 2, '0', STR_PAD_LEFT).str_pad((string) $sequence, 23, '0', STR_PAD_LEFT);
    }

    /** @return array<string, Closure(int): array{User, string}> */
    public function all(): array
    {
        $p = '/api/v1/projects';
        $long = self::id(40, 1);
        $w = $this->longHistory;
        $largeOrg = self::id(11, $this->organizations + 1);
        $owner = fn (): User => $this->user(1);

        return [
            // Lightweight reads.
            'me' => fn (int $i): array => [$this->user($this->u($i)), '/api/v1/me'],
            'profile' => fn (int $i): array => [$this->user($this->u($i)), '/api/v1/profile'],
            'billing.overview' => fn (int $i): array => [$this->user($this->u($i)), '/api/v1/billing'],
            'billing.usage' => fn (int $i): array => [$this->user($this->u($i)), '/api/v1/billing/usage'],
            'billing.plans' => fn (int $i): array => [$this->user($this->u($i)), '/api/v1/billing/plans'],
            'projects.index' => fn (int $i): array => [$this->user($this->u($i)), $p],
            // One project of a typical user.
            'project.show' => fn (int $i): array => $this->project($i, ''),
            'project.sources' => fn (int $i): array => $this->project($i, '/source-snapshots'),
            'project.analyses' => fn (int $i): array => $this->project($i, '/analyses'),
            'project.analysis' => fn (int $i): array => $this->project($i, '/analyses/'.self::id(22, $this->n($i, $this->analyses))),
            'project.analysis.result' => fn (int $i): array => $this->project($i, '/analyses/'.self::id(22, $this->n($i, $this->analyses)).'/result'),
            'project.dna' => fn (int $i): array => $this->project($i, '/dna'),
            'project.dna.show' => fn (int $i): array => $this->project($i, '/dna/'.self::id(23, $this->n($i, $this->analyses))),
            'project.competencies' => fn (int $i): array => $this->project($i, '/competencies'),
            'project.skill-gaps' => fn (int $i): array => $this->project($i, '/skill-gaps'),
            'project.skill-gap.show' => fn (int $i): array => $this->project($i, '/skill-gaps/'.self::id(25, $this->n($i, $this->analyses))),
            'project.growth' => fn (int $i): array => $this->project($i, '/growth'),
            'project.growth.timeline' => fn (int $i): array => $this->project($i, '/growth/timeline'),
            'project.history' => fn (int $i): array => $this->project($i, '/history'),
            'project.history.compare' => fn (int $i): array => $this->project($i, '/history/compare?from='.self::id(23, $this->n($i, 1)).'&to='.self::id(23, $this->n($i, $this->analyses))),
            'project.roadmaps' => fn (int $i): array => $this->project($i, '/roadmaps'),
            'project.roadmap.show' => fn (int $i): array => $this->project($i, '/roadmaps/'.self::id(29, $this->pp($i))),
            'project.challenges' => fn (int $i): array => $this->project($i, '/challenges'),
            'project.github' => fn (int $i): array => $this->project($i, '/github'),
            'project.github.imports' => fn (int $i): array => $this->project($i, '/github/imports'),
            'project.assessments' => fn (int $i): array => $this->project($i, '/assessments'),
            // A project with a long history (outlier).
            'long.dna' => fn (): array => [$owner(), "{$p}/{$long}/dna"],
            'long.dna.deep-page' => fn (): array => [$owner(), "{$p}/{$long}/dna?page=".max(1, intdiv($w, 25) - 1)],
            'long.analyses' => fn (): array => [$owner(), "{$p}/{$long}/analyses"],
            'long.analyses.deep-page' => fn (): array => [$owner(), "{$p}/{$long}/analyses?page=".max(1, intdiv($w, 25) - 1)],
            'long.sources.deep-page' => fn (): array => [$owner(), "{$p}/{$long}/source-snapshots?page=".max(1, intdiv($w, 25) - 1)],
            'long.competencies' => fn (): array => [$owner(), "{$p}/{$long}/competencies"],
            'long.skill-gaps' => fn (): array => [$owner(), "{$p}/{$long}/skill-gaps"],
            'long.growth' => fn (): array => [$owner(), "{$p}/{$long}/growth"],
            'long.growth.timeline' => fn (): array => [$owner(), "{$p}/{$long}/growth/timeline"],
            'long.growth.timeline.deep-page' => fn (): array => [$owner(), "{$p}/{$long}/growth/timeline?page=".max(1, intdiv($w, 25) - 1)],
            'long.history' => fn (): array => [$owner(), "{$p}/{$long}/history"],
            'long.history.deep-page' => fn (): array => [$owner(), "{$p}/{$long}/history?page=".max(1, intdiv($w, 25) - 1)],
            // The same long lists in cursor mode (Phase 26): first page and a
            // cursor ~99% deep (minted for the row there, as a client walking
            // the list would hold).
            'long.history.cursor' => fn (): array => [$owner(), "{$p}/{$long}/history?cursor="],
            'long.history.cursor.deep' => fn (): array => [$owner(), "{$p}/{$long}/history?cursor=".$this->deep('history:'.$long, 'SELECT r.completed_at::text AS a, r.id AS b, d.created_at::text AS c, d.id AS d FROM dna_snapshots d JOIN analysis_runs r ON r.id = d.analysis_run_id WHERE d.project_id = ? ORDER BY r.completed_at DESC, r.id DESC, d.created_at DESC, d.id DESC', [$long], $w)],
            'long.analyses.cursor.deep' => fn (): array => [$owner(), "{$p}/{$long}/analyses?cursor=".$this->deep('analyses:'.$long, 'SELECT created_at::text, id FROM analysis_runs WHERE project_id = ? ORDER BY created_at DESC, id DESC', [$long], $w)],
            'long.sources.cursor.deep' => fn (): array => [$owner(), "{$p}/{$long}/source-snapshots?cursor=".$this->deep('source-snapshots:'.$long, 'SELECT version::text FROM source_snapshots WHERE project_id = ? ORDER BY version DESC', [$long], $w)],
            'long.growth.timeline.cursor.deep' => fn (): array => [$owner(), "{$p}/{$long}/growth/timeline?cursor=".$this->deep('growth:'.$long, 'SELECT assessed_at::text, id FROM growth_snapshots WHERE project_id = ? ORDER BY assessed_at DESC, id DESC', [$long], $w)],
            'long.history.compare' => fn (): array => [$owner(), "{$p}/{$long}/history/compare?from=".self::id(43, 1).'&to='.self::id(43, $w)],
            'long.history.show' => fn (): array => [$owner(), "{$p}/{$long}/history/".self::id(43, $w)],
            // Organizations: a typical one and the large one (outlier).
            'organizations.index' => fn (int $i): array => [$this->user($this->u($i)), '/api/v1/organizations'],
            'org.show' => fn (int $i): array => $this->organization($i, ''),
            'org.members' => fn (int $i): array => $this->organization($i, '/members'),
            'org.projects' => fn (int $i): array => $this->organization($i, '/projects'),
            'org.audit' => fn (int $i): array => $this->organization($i, '/audit-events'),
            'org.analytics' => fn (int $i): array => $this->organization($i, '/analytics'),
            'org.billing' => fn (int $i): array => $this->organization($i, '/billing'),
            'large-org.show' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}"],
            'large-org.members' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}/members"],
            'large-org.members.deep-page' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}/members?page=".max(1, intdiv($this->largeMembers, 25) - 1)],
            'large-org.projects' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}/projects"],
            'large-org.projects.deep-page' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}/projects?page=".max(1, intdiv($this->largeProjects, 25) - 1)],
            'large-org.audit' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}/audit-events"],
            'large-org.audit.cursor.deep' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}/audit-events?cursor=".$this->deep('audit:'.$largeOrg, 'SELECT created_at::text, id FROM organization_audit_events WHERE organization_id = ? ORDER BY created_at DESC, id DESC', [$largeOrg], $this->largeAudit)],
            'large-org.analytics' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}/analytics"],
            'large-org.billing' => fn (): array => [$owner(), "/api/v1/organizations/{$largeOrg}/billing"],
        ];
    }

    /** @var array<string, string> */
    private array $cursors = [];

    /**
     * A next-page cursor for the row ~99% of the way down a list.
     *
     * @param  list<string>  $bindings
     */
    private function deep(string $list, string $sql, array $bindings, int $length): string
    {
        return $this->cursors[$list] ??= (function () use ($list, $sql, $bindings, $length): string {
            $row = (array) DB::selectOne($sql.' OFFSET '.max(0, intdiv($length * 99, 100)).' LIMIT 1', $bindings);
            // Stored timestamps have whole seconds: match the raw attribute format.
            $values = array_map(static fn (mixed $v): string => (string) $v, array_values($row));

            return rawurlencode(app(CursorCodec::class)->encode($list, $values, CursorDirection::Next));
        })();
    }

    /**
     * What load-test user u may read: their own projects, their
     * organization, and whether they administer it (owners and admins).
     *
     * @return array{projects: list<string>, organization: string, admin: bool}
     */
    public function subjectsFor(int $u): array
    {
        $projects = DB::table('projects')->where('user_id', self::id(10, $u))->where('id', 'like', '020%')
            ->orderBy('id')->limit(3)->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return ['projects' => $projects, 'organization' => self::id(11, (($u - 1) % $this->organizations) + 1), 'admin' => $u <= 2 * $this->organizations];
    }

    private function u(int $i): int
    {
        // Users above 2·O are plain members (no organization of their own to own).
        return 1 + (($i * 37) % $this->users);
    }

    /** Project index for iteration i (odd = personal, even = organization). */
    private function pp(int $i): int
    {
        return 1 + (($i * 97) % $this->projects);
    }

    /** Sequence of analysis v of the iteration's project. */
    private function n(int $i, int $v): int
    {
        return ($this->pp($i) - 1) * $this->analyses + $v;
    }

    /** @return array{User, string} */
    private function project(int $i, string $path): array
    {
        $p = $this->pp($i);

        return [$this->user((($p - 1) % $this->users) + 1), '/api/v1/projects/'.self::id(20, $p).$path];
    }

    /** @return array{User, string} */
    private function organization(int $i, string $path): array
    {
        // User o owns organization o.
        $o = 1 + (($i * 7) % $this->organizations);

        return [$this->user($o), '/api/v1/organizations/'.self::id(11, $o).$path];
    }

    private function user(int $u): User
    {
        $id = self::id(10, $u);

        return $this->cache[$id] ??= User::query()->findOrFail($id);
    }
}
