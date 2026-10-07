<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Enums\GitHub\GitHubImportStatus;
use App\Jobs\AnalyzeSourceSnapshot;
use App\Jobs\ImportGitHubSource;
use App\Models\AnalysisRun;
use App\Models\GitHubConnection;
use App\Models\GitHubImport;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGitHub;
use Tests\Support\GitHubFixtures;
use Tests\Support\ZipBuilder;
use Tests\TestCase;

/**
 * GitHub imports (Phase 19): the connected branch's current commit becomes
 * an immutable source snapshot, through the same archive checks and storage
 * as uploads. Nothing from the repository is executed, the commit is never
 * taken from the client, and the same commit is never stored twice.
 */
final class GitHubImportTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    private User $owner;

    private Project $project;

    private GitHubConnection $connection;

    private string $prefix;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->prefix = 'phpunit/'.strtolower((string) Str::ulid()).'/';
        config(['codedna.sources.key_prefix' => $this->prefix]);
        $this->github = FakeGitHub::configure()->fake();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
        GitHubFixtures::account($this->owner);
        $this->connection = GitHubFixtures::connection($this->project);
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('sources');
        Storage::disk('sources')->deleteDirectory(rtrim($this->prefix, '/'));
        parent::tearDown();
    }

    private function path(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/github/imports{$suffix}";
    }

    /** Requests an import and runs the queued job. */
    private function import(): GitHubImport
    {
        $id = $this->asUser($this->owner)->postJson($this->path())->assertStatus(202)->json('data.id');
        $this->runJob($id);

        return GitHubImport::query()->findOrFail($id);
    }

    private function runJob(string $id): void
    {
        app()->call([new ImportGitHubSource($id), 'handle']);
    }

    /**
     * @return list<string>
     */
    private function storedObjects(): array
    {
        return Storage::disk('sources')->allFiles(rtrim($this->prefix, '/'));
    }

    public function test_an_import_creates_an_immutable_source_snapshot_with_provenance(): void
    {
        $response = $this->asUser($this->owner)->postJson($this->path())->assertStatus(202);
        $response->assertJsonPath('data.status', 'QUEUED')->assertJsonPath('data.ref', 'main')->assertJsonPath('data.commit_sha', null);
        Queue::assertPushedOn('github', ImportGitHubSource::class, fn (ImportGitHubSource $job): bool => $job->importId === $response->json('data.id'));

        $this->runJob($response->json('data.id'));

        $import = GitHubImport::query()->sole();
        $this->assertSame([GitHubImportStatus::Succeeded, FakeGitHub::SHA, true, null], [$import->status, $import->commit_sha, $import->created_snapshot, $import->failure_code]);
        $snapshot = SourceSnapshot::query()->sole();
        $archive = $this->github->archives[FakeGitHub::SHA];
        $this->assertSame($import->source_snapshot_id, $snapshot->id);
        $this->assertSame(['REPOSITORY', 1, hash('sha256', $archive), strlen($archive), 2, 'python'], [
            $snapshot->source_type->value, $snapshot->version, $snapshot->source_hash, $snapshot->size_bytes, $snapshot->file_count, $snapshot->primary_language,
        ]);
        $this->assertEquals([
            'provider' => 'github', 'repository_id' => FakeGitHub::REPOSITORY_ID, 'repository' => 'octo-org/billing-service',
            'ref' => 'main', 'commit_sha' => FakeGitHub::SHA, 'import_id' => $import->id,
        ], array_diff_key($snapshot->metadata['provenance'], ['imported_at' => true]));
        // Stored privately, exactly the bytes GitHub served, under a server-generated key.
        $this->assertSame([$this->prefix."projects/{$this->project->id}/snapshots/{$snapshot->id}/source.zip"], $this->storedObjects());
        $this->assertSame($archive, Storage::disk('sources')->get($snapshot->storage_key));
        $connection = $this->connection->refresh();
        $this->assertSame(FakeGitHub::SHA, $connection->last_imported_commit_sha);
        $this->assertNotNull($connection->last_imported_at);

        $this->asUser($this->owner)->getJson($this->path("/{$import->id}"))->assertOk()
            ->assertJsonPath('data.status', 'SUCCEEDED')
            ->assertJsonPath('data.source_snapshot', ['id' => $snapshot->id, 'version' => 1])
            ->assertJsonPath('data.created_snapshot', true)
            ->assertJsonPath('data.commit_sha', FakeGitHub::SHA);
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/source-snapshots")->assertJsonPath('data.0.source_type', 'REPOSITORY');
    }

    public function test_the_installation_token_is_read_only_scoped_to_one_repository_and_never_stored_or_queued(): void
    {
        $import = $this->import();

        $mint = $this->github->sent('POST', '#/app/installations/\d+/access_tokens$#');
        $this->assertCount(1, $mint);
        $this->assertSame(['repository_ids' => [FakeGitHub::REPOSITORY_ID], 'permissions' => ['contents' => 'read', 'metadata' => 'read']], $mint[0]->data());
        $download = $this->github->sent('GET', '#/legacy\.zip/#')[0];
        $this->assertFalse($download->hasHeader('Authorization'), 'the download URL is its own credential; no token is forwarded');
        Queue::assertPushed(ImportGitHubSource::class, fn (ImportGitHubSource $job): bool => ! str_contains(serialize($job), 'ghs_') && ! str_contains(serialize($job), 'ghu_'));

        $haystack = json_encode([GitHubImport::query()->get(), GitHubConnection::query()->get(), SourceSnapshot::query()->get(),
            array_map(fn (MessageLogged $l): array => [$l->message, $l->context], $this->logs),
            $this->asUser($this->owner)->getJson($this->path("/{$import->id}"))->json()]);
        foreach ([FakeGitHub::INSTALLATION_TOKEN, FakeGitHub::USER_TOKEN, FakeGitHub::DOWNLOAD_TOKEN, FakeGitHub::CODELOAD, FakeGitHub::API] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $haystack);
        }
    }

    public function test_the_same_commit_is_never_stored_twice(): void
    {
        $first = $this->import();
        $second = $this->import();

        $this->assertSame([GitHubImportStatus::Succeeded, false], [$second->status, $second->created_snapshot]);
        $this->assertSame($first->source_snapshot_id, $second->source_snapshot_id);
        $this->assertSame(1, SourceSnapshot::query()->count());
        $this->assertCount(1, $this->storedObjects());
        $this->assertCount(1, $this->github->sent('GET', '#/zipball/#'), 'a known commit is not downloaded again');
        $this->asUser($this->owner)->getJson($this->path("/{$second->id}"))->assertJsonPath('data.created_snapshot', false);
    }

    public function test_a_new_commit_creates_a_new_snapshot(): void
    {
        $this->import();
        $next = str_repeat('a', 40);
        $this->github->branches[FakeGitHub::REPOSITORY_ID]['main'] = $next;
        $this->github->archives[$next] = (new ZipBuilder)->file('octo-org-billing-service-aaaaaaa/app.py', "def main():\n    return 2\n")->comment($next)->build();

        $second = $this->import();

        $this->assertTrue($second->created_snapshot);
        $this->assertSame([1, 2], SourceSnapshot::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame($next, $this->connection->refresh()->last_imported_commit_sha);
    }

    /**
     * Another import of the same commit recorded its snapshot while this one
     * was downloading: this one reuses it and deletes its own object.
     */
    public function test_a_snapshot_recorded_meanwhile_is_reused(): void
    {
        $id = $this->asUser($this->owner)->postJson($this->path())->assertStatus(202)->json('data.id');
        $winner = null;
        $this->github->override = function (Request $r) use (&$winner) {
            if ($winner === null && str_contains($r->url(), 'legacy.zip')) {
                $winner = SourceSnapshot::factory()->for($this->project)->create();
                $now = Carbon::now();
                $old = (array) DB::table('github_connections')->where('id', $this->connection->id)->first();
                $oldId = strtolower((string) Str::ulid());
                DB::table('github_connections')->insert(['id' => $oldId, 'status' => 'DISCONNECTED', 'disconnected_at' => $now] + $old);
                DB::table('github_imports')->insert([
                    'id' => strtolower((string) Str::ulid()), 'github_connection_id' => $oldId, 'project_id' => $this->project->id, 'user_id' => $this->owner->id,
                    'repository_id' => FakeGitHub::REPOSITORY_ID, 'repository_full_name' => 'octo-org/billing-service', 'ref' => 'main', 'commit_sha' => FakeGitHub::SHA,
                    'status' => 'SUCCEEDED', 'source_snapshot_id' => $winner->id, 'created_snapshot' => true, 'started_at' => $now, 'completed_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return null;
        };

        $this->runJob($id);

        $import = GitHubImport::query()->findOrFail($id);
        $this->assertNotNull($winner);
        $this->assertSame(['SUCCEEDED', false, $winner->id], [$import->status->value, $import->created_snapshot, $import->source_snapshot_id]);
        $this->assertSame([], $this->storedObjects(), 'the losing object is deleted');
        $this->assertSame(1, SourceSnapshot::query()->count());
    }

    public function test_a_repeated_request_returns_the_import_in_progress(): void
    {
        $first = $this->asUser($this->owner)->postJson($this->path())->assertStatus(202)->json('data.id');

        $this->asUser($this->owner)->postJson($this->path())->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first);
        $this->assertSame(1, GitHubImport::query()->count());
        Queue::assertPushed(ImportGitHubSource::class, 1);
    }

    public function test_a_stale_import_no_longer_blocks(): void
    {
        $first = $this->asUser($this->owner)->postJson($this->path())->assertStatus(202)->json('data.id');
        $this->travel(16)->minutes();

        $second = $this->asUser($this->owner)->postJson($this->path())->assertStatus(202)->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(['FAILED', 'GITHUB_IMPORT_FAILED'], [GitHubImport::query()->findOrFail($first)->status->value, GitHubImport::query()->findOrFail($first)->failure_code]);
    }

    public function test_the_request_carries_no_fields(): void
    {
        foreach ([['commit_sha' => str_repeat('a', 40)], ['ref' => 'other'], ['archive_url' => 'https://evil.test/x.zip'], ['repository_id' => 1]] as $body) {
            $this->asUser($this->owner)->postJson($this->path(), $body)->assertUnprocessable();
        }
        $this->assertSame(0, GitHubImport::query()->count());
    }

    public function test_importing_rechecks_that_the_user_still_has_access(): void
    {
        unset($this->github->repositories[FakeGitHub::REPOSITORY_ID]);

        $this->asUser($this->owner)->postJson($this->path())->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_REPOSITORY_NOT_FOUND');
        $this->assertSame(0, GitHubImport::query()->count());
    }

    public function test_no_import_without_a_connection_or_authorization(): void
    {
        $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/github")->assertOk();
        $this->asUser($this->owner)->postJson($this->path())->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_NOT_CONNECTED');

        $this->connection = GitHubFixtures::connection($this->project);
        $this->asUser($this->owner)->deleteJson('/api/v1/github')->assertNoContent();
        $this->asUser($this->owner)->postJson($this->path())->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_AUTH_REQUIRED');
        $this->assertSame(0, GitHubImport::query()->count());
    }

    /**
     * @return array<string, array{0: callable(ZipBuilder): ZipBuilder, 1: string, 2?: array<string, int>}>
     */
    public static function rejectedArchives(): array
    {
        return [
            'path traversal' => [fn (ZipBuilder $z) => $z->file('../escape.py', 'x'), 'SOURCE_ARCHIVE_UNSAFE'],
            'absolute path' => [fn (ZipBuilder $z) => $z->file('/etc/passwd', 'x'), 'SOURCE_ARCHIVE_UNSAFE'],
            'symlink' => [fn (ZipBuilder $z) => $z->file('repo/a.py', 'x')->symlink('repo/link', '/etc/passwd'), 'SOURCE_ARCHIVE_UNSAFE'],
            'too many files' => [fn (ZipBuilder $z) => $z->file('repo/a.py', 'x')->file('repo/b.py', 'y')->file('repo/c.py', 'z'), 'SOURCE_FILE_COUNT_EXCEEDED', ['files' => 2]],
            'oversized file' => [fn (ZipBuilder $z) => $z->file('repo/big.py', str_repeat('x', 2048)), 'SOURCE_FILE_TOO_LARGE', ['single_file_bytes' => 1024]],
            'expands too far' => [fn (ZipBuilder $z) => $z->file('repo/a.py', str_repeat('x', 900))->file('repo/b.py', str_repeat('y', 900)), 'SOURCE_UNCOMPRESSED_SIZE_EXCEEDED', ['uncompressed_bytes' => 1000]],
        ];
    }

    /**
     * The source snapshot limits of uploads apply unchanged.
     *
     * @param  callable(ZipBuilder): ZipBuilder  $build
     * @param  array<string, int>  $limits
     */
    #[DataProvider('rejectedArchives')]
    public function test_archives_outside_the_source_limits_are_rejected(callable $build, string $code, array $limits = []): void
    {
        foreach ($limits as $name => $value) {
            config(["codedna.sources.limits.{$name}" => $value]);
        }
        $this->github->archives[FakeGitHub::SHA] = $build(new ZipBuilder)->build();

        $import = $this->import();

        $this->assertSame(['FAILED', $code, null], [$import->status->value, $import->failure_code, $import->source_snapshot_id]);
        $this->assertSame(0, SourceSnapshot::query()->count());
        $this->assertSame([], $this->storedObjects(), 'a rejected archive is never stored');
    }

    public function test_invalid_and_oversized_downloads_are_rejected_without_storing_anything(): void
    {
        $this->github->archives[FakeGitHub::SHA] = 'not a zip at all';
        $this->assertSame('SOURCE_ARCHIVE_INVALID', $this->import()->failure_code);

        config(['codedna.sources.limits.archive_bytes' => 100]);
        $this->github->archives[FakeGitHub::SHA] = (new ZipBuilder)->file('repo/a.py', str_repeat('x', 500))->build();
        $this->logs = [];
        $this->assertSame('SOURCE_ARCHIVE_TOO_LARGE', $this->import()->failure_code);
        // Refused while streaming, at the archive limit: never written in full, never inspected.
        $capped = array_filter($this->logs, fn (MessageLogged $l): bool => $l->message === 'github.request_failed' && $l->context['operation'] === 'archive' && $l->context['error'] === 'too_large');
        $this->assertCount(1, $capped);

        $this->github->override = fn (Request $r) => str_contains($r->url(), 'legacy.zip') ? Http::response('<html>', 200, ['Content-Type' => 'text/html']) : null;
        $this->assertSame('GITHUB_IMPORT_FAILED', $this->import()->failure_code);
        $this->assertSame([0, []], [SourceSnapshot::query()->count(), $this->storedObjects()]);
    }

    public function test_an_archive_of_another_commit_is_rejected(): void
    {
        $this->github->archives[FakeGitHub::SHA] = (new ZipBuilder)->file('repo/a.py', 'x')->comment(str_repeat('f', 40))->build();

        $this->assertSame('SOURCE_ARCHIVE_INVALID', $this->import()->failure_code);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function foreignRedirects(): array
    {
        return [
            'other host' => ['https://evil.test/a.zip'],
            'metadata service' => ['http://169.254.169.254/latest/meta-data'],
            'internal service' => ['http://minio:9000/codedna-sources/x'],
            'plain http' => ['http://codeload.github.test/x.zip'],
            'other port' => ['https://codeload.github.test:8443/x.zip'],
            'credentials in url' => ['https://user:pass@codeload.github.test/x.zip'],
            'lookalike' => ['https://codeload.github.test.evil.test/x.zip'],
            'relative' => ['/x.zip'],
        ];
    }

    /**
     * SSRF: a download is fetched only from an allowed origin.
     */
    #[DataProvider('foreignRedirects')]
    public function test_a_download_redirect_outside_the_allowed_origins_is_never_followed(string $location): void
    {
        $this->github->override = fn (Request $r) => str_contains($r->url(), '/zipball/') ? Http::response('', 302, ['Location' => $location]) : null;

        $import = $this->import();

        $this->assertSame('GITHUB_IMPORT_FAILED', $import->failure_code);
        $fetched = array_filter($this->github->requests, fn (Request $r): bool => ! str_starts_with($r->url(), FakeGitHub::API) && ! str_starts_with($r->url(), FakeGitHub::WEB));
        $this->assertSame([], array_values($fetched));
    }

    public function test_api_redirects_are_never_followed(): void
    {
        $this->github->override = fn (Request $r) => str_contains($r->url(), '/branches/') ? Http::response('', 301, ['Location' => 'https://evil.test/']) : null;

        $this->assertSame('GITHUB_IMPORT_FAILED', $this->import()->failure_code);
        $this->assertSame([], array_values(array_filter($this->github->requests, fn (Request $r): bool => str_contains($r->url(), 'evil.test'))));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: array<string, string>, 3: string}>
     */
    public static function githubFailures(): array
    {
        return [
            'branch deleted' => ['/branches/', 404, [], 'GITHUB_BRANCH_NOT_FOUND'],
            'repository gone' => ['/repositories/', 404, [], 'GITHUB_REPOSITORY_NOT_FOUND'],
            'app uninstalled' => ['/access_tokens', 404, [], 'GITHUB_INSTALLATION_REQUIRED'],
            'rate limited' => ['/branches/', 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1'], 'GITHUB_RATE_LIMITED'],
            'secondary rate limit' => ['/zipball/', 429, ['Retry-After' => '60'], 'GITHUB_RATE_LIMITED'],
            'outage' => ['/branches/', 503, [], 'GITHUB_UNAVAILABLE'],
            'archive gone' => ['/zipball/', 404, [], 'GITHUB_REPOSITORY_NOT_FOUND'],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('githubFailures')]
    public function test_github_failures_are_recorded_as_safe_codes(string $path, int $status, array $headers, string $code): void
    {
        $id = $this->asUser($this->owner)->postJson($this->path())->assertStatus(202)->json('data.id');
        $this->github->override = fn (Request $r) => str_contains($r->url(), $path) ? Http::response(['message' => 'raw GitHub message ghs_leak'], $status, $headers) : null;

        $this->runJob($id);

        $import = GitHubImport::query()->findOrFail($id);
        $this->assertSame(['FAILED', $code], [$import->status->value, $import->failure_code]);
        $body = (string) $this->asUser($this->owner)->getJson($this->path("/{$id}"))->getContent();
        $this->assertStringNotContainsString('raw GitHub message', $body);
        $this->assertStringNotContainsString('ghs_leak', $body);
        $this->assertSame('main', $this->connection->refresh()->branch, 'a missing branch is never replaced');
        $this->assertSame(0, SourceSnapshot::query()->count());
    }

    public function test_a_disconnect_cancels_queued_imports_and_stops_running_ones(): void
    {
        $queued = $this->asUser($this->owner)->postJson($this->path())->json('data.id');
        $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/github")->assertOk();
        $this->runJob($queued);
        $this->assertSame('CANCELLED', GitHubImport::query()->findOrFail($queued)->status->value);

        // Disconnected while downloading: nothing is recorded.
        $connection = GitHubFixtures::connection($this->project);
        $running = $this->asUser($this->owner)->postJson($this->path())->json('data.id');
        $this->github->override = function (Request $r) use ($connection) {
            if (str_contains($r->url(), 'legacy.zip') && GitHubConnection::query()->findOrFail($connection->id)->isActive()) {
                $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/github")->assertOk();
            }

            return null;
        };
        $this->runJob($running);

        $this->assertSame('CANCELLED', GitHubImport::query()->findOrFail($running)->status->value);
        $this->assertSame([0, []], [SourceSnapshot::query()->count(), $this->storedObjects()]);
    }

    public function test_an_import_never_starts_an_analysis_and_the_snapshot_is_analyzable_as_usual(): void
    {
        $import = $this->import();

        $this->assertSame(0, AnalysisRun::query()->count());
        Queue::assertNotPushed(AnalyzeSourceSnapshot::class);
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/analyses", [
            'source_snapshot_id' => $import->source_snapshot_id, 'result_type' => 'static_analysis',
        ])->assertStatus(202);
        Queue::assertPushed(AnalyzeSourceSnapshot::class, 1);
    }

    public function test_disconnecting_keeps_every_snapshot_and_its_history(): void
    {
        $import = $this->import();
        $snapshot = SourceSnapshot::query()->sole()->toArray();

        $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/github")->assertOk();
        $this->asUser($this->owner)->deleteJson('/api/v1/github')->assertNoContent();

        $this->assertSame($snapshot, SourceSnapshot::query()->sole()->toArray());
        $this->assertCount(1, $this->storedObjects());
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/source-snapshots/{$snapshot['id']}")->assertOk();
        $this->asUser($this->owner)->getJson($this->path("/{$import->id}"))->assertOk()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->asUser($this->owner)->getJson($this->path())->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_a_failure_while_recording_leaves_no_object_behind(): void
    {
        $id = $this->asUser($this->owner)->postJson($this->path())->json('data.id');
        // The archive is stored, then recording the snapshot fails (rolled back with the test transaction).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_refuse_snapshot() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'refused by test'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_refuse_snapshot BEFORE INSERT ON source_snapshots FOR EACH ROW EXECUTE FUNCTION test_refuse_snapshot();
        SQL);

        $this->runJob($id);

        $this->assertSame(['FAILED', 'GITHUB_IMPORT_FAILED'], [GitHubImport::query()->findOrFail($id)->status->value, GitHubImport::query()->findOrFail($id)->failure_code]);
        $this->assertCount(1, $this->github->sent('GET', '#/legacy\.zip/#'), 'the archive was downloaded and stored');
        $this->assertSame([], $this->storedObjects(), 'and deleted again');
    }

    public function test_an_archived_project_stops_an_import(): void
    {
        $id = $this->asUser($this->owner)->postJson($this->path())->json('data.id');
        $this->project->archive();

        $this->runJob($id);

        $this->assertSame(['FAILED', 'PROJECT_ARCHIVED'], [GitHubImport::query()->findOrFail($id)->status->value, GitHubImport::query()->findOrFail($id)->failure_code]);
        $this->assertSame(0, SourceSnapshot::query()->count());
    }

    public function test_a_finished_import_is_never_run_again(): void
    {
        $import = $this->import();
        $requests = count($this->github->requests);

        $this->runJob($import->id);

        $this->assertSame($requests, count($this->github->requests));
        $this->assertSame(1, SourceSnapshot::query()->count());
    }

    public function test_imports_are_listed_newest_first_and_owner_only(): void
    {
        $first = $this->import();
        $this->travel(1)->minutes();
        $second = $this->import();

        $list = $this->asUser($this->owner)->getJson($this->path())->assertOk();
        $this->assertSame([$second->id, $first->id], array_column($list->json('data'), 'id'));
        $this->assertSame(['id', 'type', 'project_id', 'status', 'repository', 'ref', 'commit_sha', 'failure_code', 'source_snapshot', 'created_snapshot', 'created_at', 'started_at', 'completed_at'], array_keys($list->json('data.0')));
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/github")->assertJsonPath('data.latest_import.id', $second->id);

        $stranger = User::factory()->create();
        $theirs = Project::factory()->for($stranger)->create();
        $this->asUser($stranger)->getJson($this->path("/{$first->id}"))->assertNotFound();
        $this->asUser($stranger)->getJson("/api/v1/projects/{$theirs->id}/github/imports/{$first->id}")->assertNotFound();
        $this->asUser($this->owner)->getJson($this->path('/01k6p0a1b2c3d4e5f6g7h8j9zz'))->assertNotFound();
        foreach (['putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->asUser($this->owner)->{$method}($this->path("/{$first->id}"))->assertStatus(405);
        }
    }
}
