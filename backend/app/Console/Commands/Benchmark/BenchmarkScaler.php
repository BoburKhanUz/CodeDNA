<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Clones the template (one real analysis chain) into a dataset of the
 * requested scale with set-based SQL (docs/performance/benchmarking.md).
 *
 * Every column is copied from the template row except the ones listed per
 * table (identity, lineage and timestamps), which are deterministic
 * functions of the row's position. Identifiers are "0" + a two-digit table
 * code + a 23-digit sequence: valid ULIDs (digits only), stable across runs.
 *
 * Layout (U users, O organizations, P projects, A analyses per project):
 * - user u is a member of organization ((u-1) mod O)+1; users 1..O own one
 *   organization each, users O+1..2O are its admins, the rest members;
 * - project p belongs to user ((p-1) mod U)+1; even projects belong to that
 *   user's organization, odd ones are personal;
 * - each project has A source snapshots, each with one SUCCEEDED analysis and
 *   its DNA, competency, skill-gap (with results) and growth snapshots; the
 *   latest analysis also has its stored analyzer result, and the project one
 *   ACTIVE roadmap with its steps;
 * - each organization has its audit log;
 * - outliers: one personal project with a very long history and one
 *   organization with many members, projects and audit events, because
 *   per-request cost is driven by the largest owner, not by table size.
 */
final class BenchmarkScaler
{
    private const BASE = "timestamp '2025-01-01 00:00:00'";

    /** Code offsets: regular projects use codes 20-30, the long history 40-50, the large organization 70-80. */
    private const LONG_HISTORY = 20;

    private const LARGE_ORGANIZATION = 50;

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var array<int, array<string, string>> */
    private array $chains = [];

    private string $roadmap;

    private string $organization;

    /** @return array<string, int> */
    public function scale(User $template, BenchmarkScale $scale, callable $out): array
    {
        if (User::query()->where('email', 'like', '%@benchmark.invalid')->count() > 1) {
            throw new RuntimeException('The benchmark database is already scaled: run make benchmark-clean first.');
        }
        $this->loadTemplate($template);

        DB::statement('SET synchronous_commit TO off');
        $this->step($out, 'users', fn () => $this->users($scale->users, (string) $template->password));
        $this->step($out, 'organizations', fn () => $this->organizations($scale));
        $this->step($out, 'projects', fn () => $this->projects($scale));
        $this->step($out, 'histories', fn () => $this->histories(0, 1, $scale->projects, $scale->analysesPerProject));
        // Outlier: a personal project of user 1 with a long history.
        $this->step($out, 'long history', function () use ($scale): void {
            DB::statement(sprintf(
                "INSERT INTO projects (id, user_id, name, slug, source_type, language, status, created_at, updated_at)
                 VALUES (%s, %s, 'Long history', 'bench-long-history', 'UPLOAD', 'python', 'ACTIVE', %s, %s)",
                self::id(self::LONG_HISTORY + 20, '1'), self::id(10, '1'), self::BASE, self::BASE,
            ));
            $this->histories(self::LONG_HISTORY, 1, 1, $scale->longHistoryAnalyses);
        });
        $this->step($out, 'large organization', fn () => $this->largeOrganization($scale));
        $this->step($out, 'audit events', fn () => $this->auditEvents($scale));
        DB::statement('ANALYZE');

        $counts = [];
        foreach (['users', 'organizations', 'organization_memberships', 'organization_audit_events', 'projects', 'source_snapshots', 'analysis_runs', 'analysis_results', 'dna_snapshots', 'competency_snapshots', 'skill_gap_snapshots', 'skill_gap_results', 'growth_snapshots', 'growth_observations', 'roadmap_snapshots', 'roadmap_steps'] as $table) {
            $counts[$table] = (int) DB::scalar('SELECT reltuples::bigint FROM pg_class WHERE relname = ?', [$table]);
        }

        return $counts;
    }

    private function step(callable $out, string $name, callable $work): void
    {
        $started = microtime(true);
        DB::transaction($work);
        $out(sprintf('  %-20s %6.1fs', $name, microtime(true) - $started));
    }

    private function loadTemplate(User $template): void
    {
        $runs = DB::table('analysis_runs')->join('projects', 'projects.id', '=', 'analysis_runs.project_id')
            ->where('projects.user_id', $template->id)->where('analysis_runs.status', 'SUCCEEDED')
            ->orderBy('analysis_runs.created_at')->orderBy('analysis_runs.id')->pluck('analysis_runs.id')->all();
        if (count($runs) !== 2) {
            throw new RuntimeException('The template must have exactly two successful analyses.');
        }
        foreach ($runs as $i => $run) {
            $chain = ['run' => $run, 'source' => (string) DB::table('analysis_runs')->where('id', $run)->value('source_snapshot_id')];
            $chain['dna'] = (string) DB::table('dna_snapshots')->where('analysis_run_id', $run)->value('id');
            $chain['competency'] = (string) DB::table('competency_snapshots')->where('analysis_run_id', $run)->value('id');
            $chain['skill_gap'] = (string) DB::table('skill_gap_snapshots')->where('analysis_run_id', $run)->value('id');
            $chain['growth'] = (string) DB::table('growth_snapshots')->where('analysis_run_id', $run)->value('id');
            $this->chains[$i + 1] = $chain;
        }
        $this->roadmap = (string) DB::table('roadmap_snapshots')->where('user_id', $template->id)->value('id');
        $this->organization = (string) DB::table('organizations')->where('owner_user_id', $template->id)->value('id');
        if ($this->roadmap === '' || $this->organization === '') {
            throw new RuntimeException('The template is incomplete (roadmap or organization missing).');
        }
    }

    /** A deterministic ULID: "0" + table code + 23-digit sequence. */
    private static function id(int $code, string $sequence): string
    {
        return sprintf("('0' || lpad('%d', 2, '0') || lpad((%s)::text, 23, '0'))", $code, $sequence);
    }

    /**
     * INSERT INTO $table SELECT … FROM $from, copying every column from the
     * template alias "t" unless overridden.
     *
     * @param  array<string, string>  $overrides  column => SQL expression
     */
    private function clone(string $table, string $from, array $overrides): void
    {
        $this->columns[$table] ??= DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('table_name', $table)
            ->orderBy('ordinal_position')->pluck('column_name')->all();
        $select = array_map(fn (string $column): string => $overrides[$column] ?? "t.\"{$column}\"", $this->columns[$table]);
        $columns = implode(', ', array_map(fn (string $c): string => "\"{$c}\"", $this->columns[$table]));
        DB::statement("INSERT INTO {$table} ({$columns}) SELECT ".implode(', ', $select)." FROM {$from}");
    }

    private function users(int $count, string $passwordHash): void
    {
        DB::statement(sprintf(
            "INSERT INTO users (id, name, email, password, created_at, updated_at)
             SELECT %s, 'Benchmark User ' || u, 'user-' || u || '@benchmark.invalid', ?, %s + make_interval(secs => u), %s + make_interval(secs => u)
             FROM generate_series(1, %d) AS u",
            self::id(10, 'u'), self::BASE, self::BASE, $count,
        ), [$passwordHash]);
    }

    private function organizations(BenchmarkScale $scale): void
    {
        $o = $scale->organizations;
        $u = $scale->users;
        // An organization and its OWNER membership must exist together (deferred owner invariant).
        DB::statement(sprintf(
            "INSERT INTO organizations (id, owner_user_id, name, slug, status, created_at, updated_at)
             SELECT %s, %s, 'Benchmark Org ' || o, 'bench-org-' || o, 'ACTIVE', %s, %s FROM generate_series(1, %d) AS o",
            self::id(11, 'o'), self::id(10, 'o'), self::BASE, self::BASE, $o,
        ));
        DB::statement(sprintf(
            "INSERT INTO organization_memberships (id, organization_id, user_id, role, status, joined_at, created_at, updated_at)
             SELECT %s, %s, %s,
                    CASE WHEN u <= %d THEN 'OWNER' WHEN u <= %d THEN 'ADMIN' ELSE 'MEMBER' END,
                    'ACTIVE', %s + make_interval(secs => u), %s, %s
             FROM generate_series(1, %d) AS u",
            self::id(12, 'u'), self::id(11, "((u - 1) % {$o}) + 1"), self::id(10, 'u'), $o, 2 * $o, self::BASE, self::BASE, self::BASE, $u,
        ));
        $this->clone('organization_billing_accounts', "generate_series(1, {$o}) AS o CROSS JOIN (SELECT * FROM organization_billing_accounts WHERE organization_id = '{$this->organization}') t", [
            'organization_id' => self::id(11, 'o'),
        ]);
    }

    private function projects(BenchmarkScale $scale): void
    {
        $u = $scale->users;
        $o = $scale->organizations;
        $owner = "((p - 1) % {$u}) + 1";
        DB::statement(sprintf(
            "INSERT INTO projects (id, user_id, organization_id, name, slug, source_type, language, status, created_at, updated_at)
             SELECT %s, %s, CASE WHEN p %% 2 = 0 THEN %s END, 'Benchmark project ' || p, 'bench-p' || p, 'UPLOAD', 'python', 'ACTIVE',
                    %s + make_interval(secs => p), %s + make_interval(secs => p)
             FROM generate_series(1, %d) AS p",
            self::id(20, 'p'), self::id(10, $owner), self::id(11, "(({$owner}) - 1) % {$o} + 1"), self::BASE, self::BASE, $scale->projects,
        ));
    }

    /**
     * Source snapshots, analyses and every derived snapshot for projects
     * $first..$first+$count-1 of code range $offset, $analyses each.
     */
    private function histories(int $offset, int $first, int $count, int $analyses): void
    {
        $a = $analyses;
        $n = "((s.p - 1) * {$a} + s.v)";
        $prev = "((s.p - 1) * {$a} + s.v - 1)";
        $series = "(SELECT p, v, CASE WHEN v = 1 THEN 1 ELSE 2 END AS c FROM generate_series({$first}, ".($first + $count - 1).") AS p CROSS JOIN generate_series(1, {$a}) AS v) s";
        $at = self::BASE.' + make_interval(hours => s.v, secs => s.p)';
        $project = self::id(20 + $offset, 's.p');
        $id = fn (int $code, string $seq = ''): string => self::id($code + $offset, $seq === '' ? $n : $seq);
        $chain = fn (string $key): string => "CASE s.c WHEN 1 THEN '{$this->chains[1][$key]}' ELSE '{$this->chains[2][$key]}' END";

        // Materialize the owner once per project instead of a subquery per row.
        DB::statement('CREATE TEMP TABLE IF NOT EXISTS bench_owner (p bigint PRIMARY KEY, user_id char(26)) ON COMMIT DROP');
        DB::statement('TRUNCATE bench_owner');
        DB::statement(sprintf('INSERT INTO bench_owner SELECT p, (SELECT user_id FROM projects WHERE id = %s) FROM generate_series(%d, %d) AS p', self::id(20 + $offset, 'p'), $first, $first + $count - 1));
        $user = 'o.user_id';
        $from = fn (string $table, string $templateKey): string => "{$series} JOIN bench_owner o ON o.p = s.p JOIN {$table} t ON t.id = {$chain($templateKey)}";

        $this->clone('source_snapshots', $from('source_snapshots', 'source'), [
            'id' => $id(21), 'project_id' => $project, 'version' => 's.v',
            'storage_key' => "'benchmark/no-object/' || {$id(21)}",
            'source_hash' => "encode(sha256(convert_to({$id(21)}, 'UTF8')), 'hex')",
            'idempotency_key_hash' => 'NULL', 'created_at' => $at,
        ]);
        $this->clone('analysis_runs', $from('analysis_runs', 'run'), [
            'id' => $id(22), 'project_id' => $project, 'source_snapshot_id' => $id(21), 'idempotency_key' => $id(22),
            'started_at' => $at, 'completed_at' => "{$at} + interval '20 seconds'", 'created_at' => $at, 'updated_at' => "{$at} + interval '20 seconds'",
            'metadata' => "jsonb_build_object('requested_by', {$user})",
        ]);
        $this->clone('analysis_results', "{$series} JOIN analysis_results t ON t.analysis_run_id = {$chain('run')} WHERE s.v = {$a}", [
            'analysis_run_id' => $id(22), 'created_at' => $at,
        ]);
        $this->clone('dna_snapshots', $from('dna_snapshots', 'dna'), [
            'id' => $id(23), 'user_id' => $user, 'project_id' => $project, 'analysis_run_id' => $id(22), 'source_snapshot_id' => $id(21), 'created_at' => $at,
        ]);
        $lineage = ['user_id' => $user, 'project_id' => $project, 'dna_snapshot_id' => $id(23), 'analysis_run_id' => $id(22), 'source_snapshot_id' => $id(21), 'created_at' => $at];
        $this->clone('competency_snapshots', $from('competency_snapshots', 'competency'), ['id' => $id(24)] + $lineage);
        $this->clone('skill_gap_snapshots', $from('skill_gap_snapshots', 'skill_gap'), ['id' => $id(25), 'competency_snapshot_id' => $id(24)] + $lineage);
        $this->clone('skill_gap_results', "{$series} JOIN bench_owner o ON o.p = s.p JOIN skill_gap_results t ON t.skill_gap_snapshot_id = {$chain('skill_gap')}", [
            'id' => $id(26, "{$n} * 100 + t.position"), 'skill_gap_snapshot_id' => $id(25), 'project_id' => $project, 'user_id' => $user, 'created_at' => $at,
        ]);
        $previous = fn (int $code): string => 'CASE WHEN s.v = 1 THEN NULL ELSE '.self::id($code + $offset, $prev).' END';
        $this->clone('growth_snapshots', $from('growth_snapshots', 'growth'), [
            'id' => $id(27), 'skill_gap_snapshot_id' => $id(25), 'competency_snapshot_id' => $id(24), 'assessed_at' => "{$at} + interval '20 seconds'",
            'previous_skill_gap_snapshot_id' => $previous(25), 'previous_competency_snapshot_id' => $previous(24),
            'previous_dna_snapshot_id' => $previous(23), 'previous_analysis_run_id' => $previous(22), 'previous_source_snapshot_id' => $previous(21),
            'previous_assessed_at' => "CASE WHEN s.v = 1 THEN NULL ELSE {$at} - interval '1 hour' + interval '20 seconds' END",
        ] + $lineage);
        $this->clone('growth_observations', "{$series} JOIN bench_owner o ON o.p = s.p JOIN growth_observations t ON t.growth_snapshot_id = '{$this->chains[2]['growth']}' WHERE s.v > 1", [
            'id' => $id(28, "{$n} * 100 + t.position"), 'growth_snapshot_id' => $id(27), 'project_id' => $project, 'user_id' => $user, 'created_at' => $at,
        ]);

        // One ACTIVE roadmap per project, from its latest assessment.
        $latest = "{$series} JOIN bench_owner o ON o.p = s.p JOIN roadmap_snapshots t ON t.id = '{$this->roadmap}' WHERE s.v = {$a}";
        $this->clone('roadmap_snapshots', $latest, [
            'id' => self::id(29 + $offset, 's.p'), 'skill_gap_snapshot_id' => $id(25), 'competency_snapshot_id' => $id(24),
            'status' => "'ACTIVE'", 'superseded_by_id' => 'NULL', 'superseded_at' => 'NULL', 'completed_at' => 'NULL', 'updated_at' => $at,
        ] + $lineage);
        $this->clone('roadmap_steps', "{$series} JOIN bench_owner o ON o.p = s.p JOIN roadmap_steps t ON t.roadmap_snapshot_id = '{$this->roadmap}' WHERE s.v = {$a}", [
            'id' => self::id(30 + $offset, 's.p * 100 + t.position'), 'roadmap_snapshot_id' => self::id(29 + $offset, 's.p'),
            'project_id' => $project, 'user_id' => $user, 'created_at' => $at,
        ]);
    }

    private function largeOrganization(BenchmarkScale $scale): void
    {
        // Organization O+1, owned by user 1, with the first N users as members
        // and its own projects (2 analyses each).
        $org = self::id(11, (string) ($scale->organizations + 1));
        DB::statement(sprintf(
            "INSERT INTO organizations (id, owner_user_id, name, slug, status, created_at, updated_at)
             VALUES (%s, %s, 'Benchmark Large Org', 'bench-org-large', 'ACTIVE', %s, %s)",
            $org, self::id(10, '1'), self::BASE, self::BASE,
        ));
        DB::statement(sprintf(
            "INSERT INTO organization_memberships (id, organization_id, user_id, role, status, joined_at, created_at, updated_at)
             SELECT %s, %s, %s, CASE WHEN u = 1 THEN 'OWNER' WHEN u <= 20 THEN 'ADMIN' ELSE 'MEMBER' END,
                    CASE WHEN u %% 50 = 0 THEN 'SUSPENDED' ELSE 'ACTIVE' END, %s, %s, %s
             FROM generate_series(1, %d) AS u",
            self::id(13, 'u'), $org, self::id(10, 'u'), self::BASE, self::BASE, self::BASE, $scale->largeOrganizationMembers,
        ));
        $this->clone('organization_billing_accounts', "(SELECT * FROM organization_billing_accounts WHERE organization_id = '{$this->organization}') t", [
            'organization_id' => $org,
        ]);
        DB::statement(sprintf(
            "INSERT INTO projects (id, user_id, organization_id, name, slug, source_type, language, status, created_at, updated_at)
             SELECT %s, %s, %s, 'Large org project ' || p, 'bench-large-p' || p, 'UPLOAD', 'python',
                    CASE WHEN p %% 10 = 0 THEN 'ARCHIVED' ELSE 'ACTIVE' END, %s + make_interval(secs => p), %s + make_interval(secs => p)
             FROM generate_series(1, %d) AS p",
            self::id(self::LARGE_ORGANIZATION + 20, 'p'), self::id(10, '((p - 1) % 20) + 1'), $org, self::BASE, self::BASE, $scale->largeOrganizationProjects,
        ));
        $this->histories(self::LARGE_ORGANIZATION, 1, $scale->largeOrganizationProjects, 2);
    }

    private function auditEvents(BenchmarkScale $scale): void
    {
        $actions = "(ARRAY['MEMBER_INVITED','MEMBER_JOINED','MEMBER_ROLE_CHANGED','PROJECT_CREATED','ORGANIZATION_UPDATED'])";
        $insert = fn (int $code, string $org, string $actor, int $from, int $to, string $e): string => sprintf(
            "INSERT INTO organization_audit_events (id, organization_id, actor_user_id, action, target_type, target_id, metadata, request_id, created_at)
             SELECT %s, %s, %s, %s[1 + (%s %% 5)], 'user', %s, jsonb_build_object('role', 'MEMBER'), NULL, %s + make_interval(secs => %s)
             FROM generate_series(%d, %d) AS %s",
            self::id($code, $e), $org, $actor, $actions, $e, $actor, self::BASE, $e, $from, $to, $e,
        );
        $o = $scale->organizations;
        $per = $scale->auditEventsPerOrganization;
        // Events e = 1..O*per; event e belongs to organization ((e-1) mod O)+1.
        DB::statement($insert(60, self::id(11, "((e - 1) % {$o}) + 1"), self::id(10, "((e - 1) % {$o}) + 1"), 1, $o * $per, 'e'));
        DB::statement($insert(61, self::id(11, (string) ($o + 1)), self::id(10, '1'), 1, $scale->largeOrganizationAuditEvents, 'e'));
    }
}
