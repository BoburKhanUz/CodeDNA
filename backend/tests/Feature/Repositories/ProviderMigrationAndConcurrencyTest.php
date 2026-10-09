<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Actions\Repositories\CompleteProviderAuthorization;
use App\Actions\Repositories\RequestProviderImport;
use App\Actions\Repositories\RunProviderImport;
use App\Actions\Repositories\StartProviderAuthorization;
use App\Enums\GitHub\GitHubImportStatus;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\RepositoryProviderAccount;
use App\Models\RepositoryProviderImport;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingFixtures;
use Tests\Support\FakeProviders;
use Tests\Support\ProviderFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Committed-data tests (no RefreshDatabase transaction) for GitLab and
 * Bitbucket Cloud (Phase 28): concurrent import requests, duplicate job
 * deliveries, the quota's last unit, one OAuth state completed twice at once,
 * all in forked processes against real PostgreSQL; and the migration's
 * rollback and re-application. Everything created is deleted.
 */
final class ProviderMigrationAndConcurrencyTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_20_000001_create_repository_provider_tables.php';

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
        $this->dir = sys_get_temp_dir().'/codedna-providers-'.Str::random(8);
        mkdir($this->dir);
        $this->prefix = 'phpunit/'.strtolower((string) Str::ulid()).'/';
        config(['codedna.sources.key_prefix' => $this->prefix]);
        Queue::fake();
        FakeProviders::configure()->fake();
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        $users = [];
        foreach ($this->projects as $project) {
            $id = $project->id;
            DB::table('repository_provider_imports')->where('project_id', $id)->delete();
            DB::table('repository_provider_connections')->where('project_id', $id)->delete();
            DB::table('source_snapshots')->where('project_id', $id)->delete();
            $users[] = (string) $project->user_id;
        }
        foreach (array_unique($users) as $user) {
            DB::table('repository_provider_oauth_states')->where('user_id', $user)->delete();
            DB::table('repository_provider_accounts')->where('user_id', $user)->delete();
        }
        foreach ($this->projects as $project) {
            DB::table('projects')->where('id', $project->id)->delete();
        }
        BillingFixtures::forget(array_values(array_unique($users)));
        DB::table('users')->whereIn('id', array_unique($users))->delete();
        Storage::forgetDisk('sources');
        Storage::disk('sources')->deleteDirectory(rtrim($this->prefix, '/'));
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function project(?User $owner = null): Project
    {
        return $this->projects[] = Project::factory()->for($owner ?? User::factory())->create();
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
                } catch (ApiException $e) {
                    $out = 'refused:'.$e->errorCode->value;
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

    /** @return array<string, array{0: int}> */
    public static function workers(): array
    {
        return ['2 workers' => [2], '8 workers' => [8]];
    }

    private function used(string $userId): int
    {
        return (int) DB::table('billing_usage_counters')->where('user_id', $userId)->where('quota_key', 'GITHUB_IMPORTS')->sum('used');
    }

    #[DataProvider('workers')]
    public function test_concurrent_requests_queue_one_import_and_charge_once(int $workers): void
    {
        $project = $this->project();
        $owner = User::query()->findOrFail($project->user_id);
        ProviderFixtures::account($owner, RepositoryProviderKey::GitLab);
        ProviderFixtures::connection($project, RepositoryProviderKey::GitLab);

        $results = $this->concurrently($workers, fn (): string => ($r = app(RequestProviderImport::class)->handle($project, $owner))->created
            ? 'created:'.$r->import->id : 'existing:'.$r->import->id);

        $this->assertCount(1, array_filter($results, fn (string $r): bool => str_starts_with($r, 'created:')), implode("\n", $results));
        $this->assertCount(1, array_unique(array_map(fn (string $r): string => explode(':', $r)[1] ?? $r, $results)), implode("\n", $results));
        $this->assertSame(1, RepositoryProviderImport::query()->where('project_id', $project->id)->count());
        $this->assertSame(1, $this->used((string) $owner->id));
    }

    #[DataProvider('workers')]
    public function test_duplicate_deliveries_of_one_import_run_it_once(int $workers): void
    {
        $project = $this->project();
        $owner = User::query()->findOrFail($project->user_id);
        ProviderFixtures::account($owner, RepositoryProviderKey::Bitbucket);
        ProviderFixtures::connection($project, RepositoryProviderKey::Bitbucket);
        $import = app(RequestProviderImport::class)->handle($project, $owner)->import;

        $results = $this->concurrently($workers, fn (): string => ($done = app(RunProviderImport::class)->handle($import->id)) === null ? 'skipped' : 'ran:'.$done->status->value);

        $this->assertCount(1, array_filter($results, fn (string $r): bool => $r === 'ran:SUCCEEDED'), implode("\n", $results));
        $this->assertCount($workers - 1, array_filter($results, fn (string $r): bool => $r === 'skipped'), implode("\n", $results));
        $this->assertSame(1, SourceSnapshot::query()->where('project_id', $project->id)->count());
        $this->assertCount(1, Storage::disk('sources')->allFiles(rtrim($this->prefix, '/')));
        $this->assertSame(GitHubImportStatus::Succeeded, RepositoryProviderImport::query()->findOrFail($import->id)->status);
    }

    /**
     * The last unit of the monthly quota, asked for from several projects at
     * once: exactly one import is queued, the rest are refused.
     */
    public function test_concurrent_requests_across_projects_never_exceed_the_quota(): void
    {
        $owner = User::factory()->create();
        ProviderFixtures::account($owner, RepositoryProviderKey::GitLab);
        $projects = [];
        for ($i = 0; $i < 4; $i++) {
            $projects[] = $project = $this->project($owner);
            ProviderFixtures::connection($project, RepositoryProviderKey::GitLab);
        }
        DB::table('billing_usage_counters')->insert(['user_id' => $owner->id, 'quota_key' => 'GITHUB_IMPORTS', 'period_start' => now()->startOfMonth(), 'used' => 19]);

        $results = $this->concurrently(4, fn (int $i): string => app(RequestProviderImport::class)->handle($projects[$i], $owner)->created ? 'created' : 'existing');

        sort($results);
        $this->assertSame(['created', 'refused:QUOTA_EXCEEDED', 'refused:QUOTA_EXCEEDED', 'refused:QUOTA_EXCEEDED'], $results);
        $this->assertSame(20, $this->used((string) $owner->id));
        $this->assertSame(1, RepositoryProviderImport::query()->whereIn('project_id', array_map(fn (Project $p): string => $p->id, $projects))->count());
    }

    #[DataProvider('workers')]
    public function test_one_state_completed_concurrently_links_once(int $workers): void
    {
        $project = $this->project();
        $owner = User::query()->findOrFail($project->user_id);
        $url = app(StartProviderAuthorization::class)->handle($owner, RepositoryProviderKey::GitLab)['authorize_url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = (string) $query['state'];

        $results = $this->concurrently($workers, function () use ($owner, $state): string {
            app(CompleteProviderAuthorization::class)->handle($owner, RepositoryProviderKey::GitLab, $state, FakeProviders::GOOD_CODE);

            return 'linked';
        });

        $this->assertCount(1, array_filter($results, fn (string $r): bool => $r === 'linked'), implode("\n", $results));
        $this->assertCount($workers - 1, array_filter($results, fn (string $r): bool => $r === 'refused:PROVIDER_STATE_INVALID'), implode("\n", $results));
        $this->assertSame(1, RepositoryProviderAccount::query()->where('user_id', $owner->id)->count());
    }

    public function test_the_migration_rolls_back_and_reapplies_without_touching_github(): void
    {
        $this->artisan('migrate:reset', ['--path' => self::MIGRATION])->assertSuccessful();
        foreach (['repository_provider_accounts', 'repository_provider_oauth_states', 'repository_provider_connections', 'repository_provider_imports'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertSame([], DB::select("SELECT proname FROM pg_proc WHERE proname LIKE 'repository_provider_%'"));
        foreach (['github_connections', 'github_imports', 'source_snapshots', 'projects'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('repository_provider_imports'));
        $this->assertSame('ACTIVE', ProviderFixtures::connection($this->project(), RepositoryProviderKey::GitLab)->status->value);
    }
}
