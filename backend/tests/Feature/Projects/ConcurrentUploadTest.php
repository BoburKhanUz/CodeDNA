<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Actions\Snapshots\StoreUploadedSource;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ZipBuilder;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency: several processes upload to one project at the same
 * moment, each with its own database connection, against real PostgreSQL
 * and MinIO. The project row lock in RecordSourceSnapshot must give every
 * upload a distinct, gap-free version.
 *
 * Rows must be committed to be visible to other processes, so this test does
 * not use RefreshDatabase's transaction; it deletes what it created.
 */
final class ConcurrentUploadTest extends TestCase
{
    private const UPLOADS = 6;

    private string $prefix;

    private ?Project $project = null;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->prefix = 'phpunit/'.strtolower((string) Str::ulid()).'/';
        config(['codedna.sources.key_prefix' => $this->prefix]);
    }

    protected function tearDown(): void
    {
        if ($this->project !== null) {
            // Snapshots are immutable through Eloquent; test cleanup uses the query builder.
            DB::table('source_snapshots')->where('project_id', $this->project->id)->delete();
            DB::table('projects')->where('id', $this->project->id)->delete();
            DB::table('users')->where('id', $this->project->user_id)->delete();
        }
        Storage::forgetDisk('sources');
        Storage::disk('sources')->deleteDirectory(rtrim($this->prefix, '/'));
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /**
     * Runs every upload in its own process, all starting at the same moment,
     * and returns what each one reported, in order.
     *
     * @param  list<string>  $paths  one archive per process
     * @return list<string>
     */
    private function race(User $user, array $paths, ?string $idempotencyKey = null): array
    {
        $resultDir = sys_get_temp_dir().'/codedna-concurrency-'.Str::random(8);
        mkdir($resultDir);

        // Children must not share the parent's connections.
        DB::disconnect();
        Storage::forgetDisk('sources');
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach ($paths as $i => $path) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    time_sleep_until($startAt);
                    $result = $this->app->make(StoreUploadedSource::class)
                        ->handle(Project::query()->findOrFail($this->project?->id), $user, $path, $idempotencyKey);
                    file_put_contents("{$resultDir}/{$i}", ($result->created ? 'created ' : 'replayed ').$result->snapshot->version.' '.$result->snapshot->id);
                } catch (ApiException $e) {
                    file_put_contents("{$resultDir}/{$i}", 'refused '.$e->errorCode->value);
                } catch (Throwable $e) {
                    file_put_contents("{$resultDir}/{$i}", 'error: '.$e::class.': '.$e->getMessage());
                }
                // Skip PHPUnit's shutdown handlers in the child.
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = [];
        foreach (array_keys($paths) as $i) {
            $results[] = (string) @file_get_contents("{$resultDir}/{$i}");
            @unlink("{$resultDir}/{$i}");
        }
        @rmdir($resultDir);

        return $results;
    }

    public function test_concurrent_uploads_get_unique_sequential_versions(): void
    {
        $this->project = Project::factory()->create();
        $user = User::query()->findOrFail($this->project->user_id);
        $paths = [];
        for ($i = 0; $i < self::UPLOADS; $i++) {
            $paths[] = $this->tempFiles[] = (new ZipBuilder)->file("src/worker{$i}.php", "<?php // {$i}")->save();
        }

        $results = $this->race($user, $paths);

        foreach ($results as $result) {
            $this->assertMatchesRegularExpression('/^created \d+ /', $result, "an upload failed: {$result}");
        }
        $versions = array_map(fn (string $r): int => (int) explode(' ', $r)[1], $results);
        sort($versions);
        $this->assertSame(range(1, self::UPLOADS), $versions);
        $this->assertSame(range(1, self::UPLOADS), SourceSnapshot::query()
            ->where('project_id', $this->project->id)->orderBy('version')->pluck('version')->all());
        $this->assertCount(self::UPLOADS, Storage::disk('sources')->allFiles(rtrim($this->prefix, '/')));
    }

    public function test_concurrent_retries_with_one_key_create_one_snapshot_and_replay_it(): void
    {
        $this->project = Project::factory()->create();
        $user = User::query()->findOrFail($this->project->user_id);
        $path = $this->tempFiles[] = (new ZipBuilder)->file('src/app.php', '<?php // retry')->save();

        $results = $this->race($user, array_fill(0, self::UPLOADS, $path), 'retry-key-0001');

        $snapshot = SourceSnapshot::query()->where('project_id', $this->project->id)->sole();
        sort($results);
        $this->assertSame(['created 1 '.$snapshot->id, ...array_fill(0, self::UPLOADS - 1, 'replayed 1 '.$snapshot->id)], $results);
        // The losers' objects were discarded: exactly one stored archive.
        $this->assertCount(1, Storage::disk('sources')->allFiles(rtrim($this->prefix, '/')));
    }

    public function test_concurrent_uploads_of_different_bytes_with_one_key_keep_only_the_first(): void
    {
        $this->project = Project::factory()->create();
        $user = User::query()->findOrFail($this->project->user_id);
        $paths = [];
        for ($i = 0; $i < self::UPLOADS; $i++) {
            $paths[] = $this->tempFiles[] = (new ZipBuilder)->file('src/app.php', "<?php // variant {$i}")->save();
        }

        $results = $this->race($user, $paths, 'reused-key-0001');

        $snapshot = SourceSnapshot::query()->where('project_id', $this->project->id)->sole();
        sort($results);
        $this->assertSame(['created 1 '.$snapshot->id, ...array_fill(0, self::UPLOADS - 1, 'refused IDEMPOTENCY_KEY_REUSED')], $results);
        $this->assertCount(1, Storage::disk('sources')->allFiles(rtrim($this->prefix, '/')));
    }
}
