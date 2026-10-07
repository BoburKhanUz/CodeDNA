<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Enums\GitHub\GitHubImportStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\GitHubConnection;
use App\Models\GitHubImport;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

/**
 * The GitHub tables: ownership lineage, one active connection, immutable
 * identity and finished imports, one snapshot per commit, and no cascades.
 */
final class GitHubStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private GitHubConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
        $this->connection = GitHubFixtures::connection($this->project);
    }

    private function assertRefused(Closure $statement, string $constraint): void
    {
        try {
            DB::transaction(function () use ($statement) {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $statement();
            });
            $this->fail("Expected the database to refuse ({$constraint}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function importRow(array $changes = []): array
    {
        $now = Carbon::now();

        return $changes + [
            'id' => strtolower((string) Str::ulid()),
            'github_connection_id' => $this->connection->id,
            'project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'repository_id' => $this->connection->repository_id,
            'repository_full_name' => 'octo-org/billing-service',
            'ref' => 'main',
            'status' => 'QUEUED',
            'created_snapshot' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function snapshot(): SourceSnapshot
    {
        return SourceSnapshot::factory()->for($this->project)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function succeeded(SourceSnapshot $snapshot, string $sha, bool $created = true): array
    {
        return ['status' => 'SUCCEEDED', 'commit_sha' => $sha, 'source_snapshot_id' => $snapshot->id, 'created_snapshot' => $created, 'started_at' => Carbon::now(), 'completed_at' => Carbon::now()];
    }

    public function test_one_active_connection_per_project_and_owner_lineage(): void
    {
        $row = (array) DB::table('github_connections')->where('id', $this->connection->id)->first();
        $this->assertRefused(fn () => DB::table('github_connections')->insert(['id' => strtolower((string) Str::ulid())] + $row), 'github_connections_one_active_unique');
        $this->assertRefused(fn () => DB::table('github_connections')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => User::factory()->create()->id, 'status' => 'DISCONNECTED', 'disconnected_at' => Carbon::now()] + $row), 'github_connections_project_foreign');
        $this->assertRefused(fn () => DB::table('github_connections')->insert(['id' => strtolower((string) Str::ulid()), 'status' => 'DISCONNECTED'] + $row), 'github_connections_disconnected_iff');
        $this->assertRefused(fn () => DB::table('github_connections')->insert(['id' => strtolower((string) Str::ulid()), 'status' => 'DISCONNECTED', 'disconnected_at' => Carbon::now(), 'repository_full_name' => 'evil/repo'] + $row), 'github_connections_full_name');
        $this->assertRefused(fn () => DB::table('github_connections')->insert(['id' => strtolower((string) Str::ulid()), 'status' => 'DISCONNECTED', 'disconnected_at' => Carbon::now(), 'branch' => '$(id)'] + $row), 'github_connections_branch_format');
    }

    public function test_connection_identity_never_changes_and_a_disconnected_connection_is_frozen(): void
    {
        foreach (['repository_id' => 1, 'installation_id' => 2, 'project_id' => Project::factory()->for($this->owner)->create()->id, 'connected_at' => '2020-01-01 00:00:00'] as $column => $value) {
            $this->assertRefused(fn () => DB::table('github_connections')->where('id', $this->connection->id)->update([$column => $value]), 'identity is immutable');
        }
        DB::table('github_connections')->where('id', $this->connection->id)->update(['branch' => 'develop']);
        DB::table('github_connections')->where('id', $this->connection->id)->update(['status' => 'DISCONNECTED', 'disconnected_at' => Carbon::now()]);
        $this->assertRefused(fn () => DB::table('github_connections')->where('id', $this->connection->id)->update(['status' => 'ACTIVE', 'disconnected_at' => null]), 'never changes');
        $this->assertRefused(fn () => DB::table('github_connections')->where('id', $this->connection->id)->update(['branch' => 'main']), 'never changes');

        $this->expectException(DomainRuleViolation::class);
        $this->connection->refresh()->delete();
    }

    public function test_imports_keep_their_lineage(): void
    {
        $other = Project::factory()->for($this->owner)->create();
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['project_id' => $other->id])), 'github_imports_connection_foreign');
        $theirs = SourceSnapshot::factory()->for($other)->create();
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow($this->succeeded($theirs, str_repeat('a', 40)))), 'github_imports_snapshot_foreign');
    }

    public function test_import_constraints(): void
    {
        $snapshot = $this->snapshot();
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['status' => 'DONE'])), 'github_imports_status_valid');
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['status' => 'SUCCEEDED', 'commit_sha' => str_repeat('a', 40), 'started_at' => Carbon::now(), 'completed_at' => Carbon::now()])), 'github_imports_succeeded_iff');
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['status' => 'FAILED', 'completed_at' => Carbon::now()])), 'github_imports_failed_iff');
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['status' => 'FAILED', 'failure_code' => 'raw github message', 'completed_at' => Carbon::now()])), 'github_imports_failure_code_format');
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['commit_sha' => 'HEAD'])), 'github_imports_commit_sha');
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['created_snapshot' => true])), 'github_imports_created_snapshot');
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['ref' => 'a b'])), 'github_imports_ref_format');
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['completed_at' => null] + $this->succeeded($snapshot, str_repeat('a', 40)))), 'github_imports_completed_iff_terminal');
    }

    public function test_one_import_in_progress_and_one_snapshot_per_commit(): void
    {
        DB::table('github_imports')->insert($this->importRow());
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow(['status' => 'RUNNING', 'started_at' => Carbon::now()])), 'github_imports_one_active_unique');

        $sha = str_repeat('a', 40);
        DB::table('github_imports')->insert($this->importRow($this->succeeded($this->snapshot(), $sha)));
        $this->assertRefused(fn () => DB::table('github_imports')->insert($this->importRow($this->succeeded($this->snapshot(), $sha))), 'github_imports_commit_unique');
        // A reuse of the same commit is fine; so is another repository's identical commit.
        DB::table('github_imports')->insert($this->importRow($this->succeeded(SourceSnapshot::query()->firstOrFail(), $sha, false)));
        $this->assertSame(3, DB::table('github_imports')->count());
    }

    public function test_finished_imports_never_change(): void
    {
        $snapshot = $this->snapshot();
        $id = DB::table('github_imports')->insertGetId($this->importRow($this->succeeded($snapshot, str_repeat('a', 40))));
        foreach (['status' => 'FAILED', 'commit_sha' => str_repeat('b', 40), 'source_snapshot_id' => null, 'created_snapshot' => false] as $column => $value) {
            $this->assertRefused(fn () => DB::table('github_imports')->where('id', $id)->update([$column => $value]), 'finished import is immutable');
        }
        $running = DB::table('github_imports')->insertGetId($this->importRow(['status' => 'RUNNING', 'started_at' => Carbon::now()]));
        $this->assertRefused(fn () => DB::table('github_imports')->where('id', $running)->update(['status' => 'QUEUED']), 'only moves forward');
        $this->assertRefused(fn () => DB::table('github_imports')->where('id', $running)->update(['ref' => 'other']), 'identity is immutable');

        $import = GitHubImport::query()->findOrFail($id);
        $this->assertSame(GitHubImportStatus::Succeeded, $import->status);
        try {
            $import->forceFill(['failure_code' => 'X'])->save();
            $this->fail('updated');
        } catch (DomainRuleViolation) {
        }
        $this->expectException(DomainRuleViolation::class);
        $import->delete();
    }

    public function test_history_cannot_be_deleted_from_under_an_import(): void
    {
        $snapshot = $this->snapshot();
        DB::table('github_imports')->insert($this->importRow($this->succeeded($snapshot, str_repeat('a', 40))));

        $this->assertRefused(fn () => DB::table('source_snapshots')->where('id', $snapshot->id)->delete(), 'github_imports_snapshot_foreign');
        $this->assertRefused(fn () => DB::table('github_connections')->where('id', $this->connection->id)->delete(), 'github_imports_connection_foreign');
    }

    public function test_states_and_accounts_are_bounded(): void
    {
        $now = Carbon::now();
        $state = ['id' => strtolower((string) Str::ulid()), 'user_id' => $this->owner->id, 'state_hash' => str_repeat('a', 64), 'expires_at' => $now->copy()->addMinutes(10), 'created_at' => $now];
        $this->assertRefused(fn () => DB::table('github_oauth_states')->insert(['state_hash' => 'plain-state'] + $state), 'github_oauth_states_hash_sha256');
        $this->assertRefused(fn () => DB::table('github_oauth_states')->insert(['expires_at' => $now->copy()->addDays(2)] + $state), 'github_oauth_states_expiry');
        $this->assertRefused(fn () => DB::table('github_oauth_states')->insert(['project_id' => Project::factory()->create()->id] + $state), 'github_oauth_states_project_foreign');
        DB::table('github_oauth_states')->insert($state);
        $this->assertRefused(fn () => DB::table('github_oauth_states')->insert(['id' => strtolower((string) Str::ulid())] + $state), 'github_oauth_states_state_hash_unique');

        GitHubFixtures::account($this->owner);
        $this->assertRefused(fn () => DB::table('github_accounts')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $this->owner->id, 'github_user_id' => 1, 'login' => 'x', 'access_token' => 'y', 'created_at' => $now, 'updated_at' => $now]), 'github_accounts_user_id_unique');
    }
}
