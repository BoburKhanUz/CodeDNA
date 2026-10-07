<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Actions\GitHub\RequestGitHubImport;
use App\Actions\GitHub\RunGitHubImport;
use App\Enums\GitHub\GitHubImportStatus;
use App\Models\GitHubImport;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGitHub;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Committed-data tests (no RefreshDatabase transaction): concurrent import
 * requests and concurrent imports of the same commit in forked processes,
 * and the Phase 19 migration's rollback and re-application. Everything
 * created is deleted.
 */
final class GitHubMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_16_000001_create_github_tables.php';

    /** @var list<Project> */
    private array $projects = [];

    private string $dir;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->dir = sys_get_temp_dir().'/codedna-github-'.Str::random(8);
        mkdir($this->dir);
        $this->prefix = 'phpunit/'.strtolower((string) Str::ulid()).'/';
        config(['codedna.sources.key_prefix' => $this->prefix]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        foreach ($this->projects as $project) {
            $id = $project->id;
            DB::table('github_imports')->where('project_id', $id)->delete();
            DB::table('github_connections')->where('project_id', $id)->delete();
            DB::table('github_oauth_states')->where('user_id', $project->user_id)->delete();
            DB::table('github_accounts')->where('user_id', $project->user_id)->delete();
            DB::table('source_snapshots')->where('project_id', $id)->delete();
            DB::table('projects')->where('id', $id)->delete();
            DB::table('users')->where('id', $project->user_id)->delete();
        }
        Storage::forgetDisk('sources');
        Storage::disk('sources')->deleteDirectory(rtrim($this->prefix, '/'));
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function project(): Project
    {
        return $this->projects[] = Project::factory()->for(User::factory())->create();
    }

    /**
     * @param  callable(int): string  $work
     * @return list<string>
     */
    private function concurrently(int $count, callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        DB::disconnect();
        $startAt = microtime(true) + 0.5;
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::purge();
                    Storage::forgetDisk('sources');
                    time_sleep_until($startAt);
                    $out = $work($i);
                } catch (Throwable $e) {
                    $out = 'error: '.$e::class.': '.$e->getMessage();
                }
                file_put_contents("{$this->dir}/{$i}", $out);
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        return array_map(fn (int $i): string => (string) @file_get_contents("{$this->dir}/{$i}"), range(0, $count - 1));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function workers(): array
    {
        return ['2 workers' => [2], '8 workers' => [8]];
    }

    #[DataProvider('workers')]
    public function test_concurrent_requests_queue_one_import(int $workers): void
    {
        FakeGitHub::configure()->fake();
        $project = $this->project();
        $owner = User::query()->findOrFail($project->user_id);
        GitHubFixtures::account($owner);
        GitHubFixtures::connection($project);

        $results = $this->concurrently($workers, fn (): string => ($r = app(RequestGitHubImport::class)->handle($project, $owner))->created
            ? 'created:'.$r->import->id : 'existing:'.$r->import->id);

        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_starts_with($r, 'created:')), implode("\n", $results));
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode(':', $r)[1] ?? $r, $results)), implode("\n", $results));
        $this->assertSame(1, GitHubImport::query()->where('project_id', $project->id)->count());
    }

    /**
     * The same import delivered to several workers at once (a redelivered or
     * duplicated job): it runs once, one snapshot and one stored object.
     */
    #[DataProvider('workers')]
    public function test_duplicate_deliveries_of_one_import_run_it_once(int $workers): void
    {
        FakeGitHub::configure()->fake();
        $project = $this->project();
        $owner = User::query()->findOrFail($project->user_id);
        GitHubFixtures::account($owner);
        GitHubFixtures::connection($project);
        $import = app(RequestGitHubImport::class)->handle($project, $owner)->import;

        $results = $this->concurrently($workers, fn (): string => ($done = app(RunGitHubImport::class)->handle($import->id)) === null ? 'skipped' : 'ran:'.$done->status->value);

        $this->assertCount(1, array_filter($results, fn (string $r): bool => $r === 'ran:SUCCEEDED'), implode("\n", $results));
        $this->assertCount($workers - 1, array_filter($results, fn (string $r): bool => $r === 'skipped'), implode("\n", $results));
        $this->assertSame(1, SourceSnapshot::query()->where('project_id', $project->id)->count());
        $this->assertCount(1, Storage::disk('sources')->allFiles(rtrim($this->prefix, '/')));
        $this->assertSame(GitHubImportStatus::Succeeded, GitHubImport::query()->findOrFail($import->id)->status);
    }

    public function test_the_migration_rolls_back_and_reapplies(): void
    {
        $this->artisan('migrate:reset', ['--path' => self::MIGRATION])->assertSuccessful();
        foreach (['github_accounts', 'github_oauth_states', 'github_connections', 'github_imports'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertSame([], DB::select("SELECT proname FROM pg_proc WHERE proname LIKE 'github_%'"));
        foreach (['source_snapshots', 'growth_snapshots', 'projects'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('github_imports'));
        $project = $this->project();
        $this->assertSame('ACTIVE', GitHubFixtures::connection($project)->status->value);
    }
}
