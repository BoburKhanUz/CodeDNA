<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\GitHub\GitHubImportStatus;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Jobs\ImportProviderSource;
use App\Models\Project;
use App\Models\RepositoryProviderAccount;
use App\Models\RepositoryProviderConnection;
use App\Models\RepositoryProviderImport;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeProviders;
use Tests\Support\ProviderFixtures;
use Tests\Support\ZipBuilder;
use Tests\TestCase;

/**
 * GitLab and Bitbucket Cloud imports (Phase 28,
 * docs/integrations/import-security.md): the shared archive core, the same
 * limits as uploads, idempotency per commit, the shared repository import
 * quota, and no snapshot from anything unsafe. Against FakeProviders.
 */
final class ProviderImportTest extends TestCase
{
    use RefreshDatabase;

    private FakeProviders $providers;

    private User $owner;

    private Project $project;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->prefix = 'phpunit/'.strtolower((string) Str::ulid()).'/';
        config(['codedna.sources.key_prefix' => $this->prefix]);
        $this->providers = FakeProviders::configure()->fake();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('sources');
        Storage::disk('sources')->deleteDirectory(rtrim($this->prefix, '/'));
        parent::tearDown();
    }

    /** @return iterable<string, array{RepositoryProviderKey}> */
    public static function providers(): iterable
    {
        yield 'GitLab' => [RepositoryProviderKey::GitLab];
        yield 'Bitbucket Cloud' => [RepositoryProviderKey::Bitbucket];
    }

    private function connect(RepositoryProviderKey $provider): RepositoryProviderConnection
    {
        ProviderFixtures::account($this->owner, $provider);

        return ProviderFixtures::connection($this->project, $provider);
    }

    private function request(): string
    {
        return (string) $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/repository-provider/imports")->assertStatus(202)->json('data.id');
    }

    private function runJob(string $id): RepositoryProviderImport
    {
        app()->call([new ImportProviderSource($id), 'handle']);

        return RepositoryProviderImport::query()->findOrFail($id);
    }

    private function import(): RepositoryProviderImport
    {
        return $this->runJob($this->request());
    }

    /** @return list<string> */
    private function stored(): array
    {
        return Storage::disk('sources')->allFiles(rtrim($this->prefix, '/'));
    }

    private function used(): int
    {
        return (int) DB::table('billing_usage_counters')->where('user_id', $this->owner->id)->where('quota_key', 'GITHUB_IMPORTS')->value('used');
    }

    #[DataProvider('providers')]
    public function test_an_import_creates_a_private_immutable_snapshot_with_provenance(RepositoryProviderKey $provider): void
    {
        $this->connect($provider);
        $id = $this->request();
        Queue::assertPushedOn('github', ImportProviderSource::class, function (ImportProviderSource $job) use ($id): bool {
            // The payload is the import ID only: no token, URL or repository data.
            $payload = serialize($job);

            return $job->importId === $id && ! str_contains($payload, FakeProviders::ACCESS_TOKEN) && ! str_contains($payload, 'gitlab.test');
        });

        $import = $this->runJob($id);

        $this->assertSame([GitHubImportStatus::Succeeded, FakeProviders::SHA, true, null], [$import->status, $import->commit_sha, $import->created_snapshot, $import->failure_code]);
        $snapshot = SourceSnapshot::query()->sole();
        $archive = $this->providers->archives[FakeProviders::SHA];
        $this->assertSame(['REPOSITORY', hash('sha256', $archive), 1], [$snapshot->source_type->value, $snapshot->source_hash, $snapshot->file_count]);
        $this->assertSame($provider->value, $snapshot->metadata['provenance']['provider']);
        $this->assertSame(ProviderFixtures::repositoryId($provider), $snapshot->metadata['provenance']['repository_id']);
        $this->assertSame(FakeProviders::SHA, $snapshot->metadata['provenance']['commit_sha']);
        // Stored privately under this project's key, like an upload.
        $this->assertSame(["{$this->prefix}projects/{$this->project->id}/snapshots/{$snapshot->id}/source.zip"], $this->stored());
        $this->assertSame(1, $this->used());
        $this->assertSame(FakeProviders::SHA, RepositoryProviderConnection::query()->sole()->last_imported_commit_sha);
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/repository-provider/imports/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'SUCCEEDED')->assertJsonPath('data.source_snapshot.id', $snapshot->id);
    }

    public function test_the_same_commit_is_never_downloaded_or_stored_twice_and_a_new_commit_is(): void
    {
        $this->connect(RepositoryProviderKey::GitLab);
        $this->import();
        $downloads = count($this->providers->requestsTo('/repository/archive.zip'));

        $repeat = $this->import();
        $this->assertSame([GitHubImportStatus::Succeeded, false], [$repeat->status, $repeat->created_snapshot]);
        $this->assertCount($downloads, $this->providers->requestsTo('/repository/archive.zip'));
        $this->assertSame(1, SourceSnapshot::query()->count());

        // The branch moved between the request and the run: the job resolves the commit when it runs.
        $next = str_repeat('c', 40);
        $id = $this->request();
        $this->providers->branches['gitlab:'.FakeProviders::GITLAB_PROJECT]['main'] = $next;
        $this->providers->archives[$next] = (new ZipBuilder)->file('acme/app/main.py', "print(2)\n")->comment($next)->build();
        $this->assertSame($next, $this->runJob($id)->commit_sha);
        $this->assertSame(2, SourceSnapshot::query()->count());
    }

    public function test_another_repository_at_the_same_commit_gets_its_own_snapshot(): void
    {
        $this->connect(RepositoryProviderKey::GitLab);
        $this->import();
        // Another repository whose branch points at the same commit SHA (a fork, say).
        $this->providers->gitlabProjects[4343] = FakeProviders::gitlabProject(4343, 'acme/fork', 'private');
        $this->providers->branches['gitlab:4343'] = ['main' => FakeProviders::SHA];
        $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/repository-provider")->assertOk();
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/repository-provider", ['provider' => 'gitlab', 'repository_id' => '4343'])->assertCreated();

        $fork = $this->import();

        $this->assertSame([GitHubImportStatus::Succeeded, true], [$fork->status, $fork->created_snapshot]);
        $this->assertSame(2, SourceSnapshot::query()->count());
    }

    public function test_a_delivery_of_an_import_already_running_does_nothing(): void
    {
        $this->connect(RepositoryProviderKey::GitLab);
        $id = $this->request();
        DB::table('repository_provider_imports')->where('id', $id)->update(['status' => 'RUNNING', 'started_at' => now()]);

        $this->assertSame(GitHubImportStatus::Running, $this->runJob($id)->status);
        $this->assertSame([], $this->providers->requestsTo('/repository/archive.zip'));
        $this->assertSame(0, SourceSnapshot::query()->count());
    }

    public function test_asking_again_while_one_is_in_progress_returns_it_and_charges_once(): void
    {
        $this->connect(RepositoryProviderKey::Bitbucket);
        $id = $this->request();
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/repository-provider/imports")
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $id);
        Queue::assertPushed(ImportProviderSource::class, 1);
        $this->assertSame(1, $this->used());
        // A duplicate delivery of the job does nothing.
        $this->runJob($id);
        $this->assertSame(GitHubImportStatus::Succeeded, $this->runJob($id)->status);
        $this->assertSame(1, SourceSnapshot::query()->count());
    }

    /** @return array<string, array{callable(ZipBuilder): ZipBuilder, string}> */
    public static function unsafeArchives(): array
    {
        return [
            'path traversal' => [fn (ZipBuilder $z): ZipBuilder => $z->file('../escape.py', 'x'), 'SOURCE_ARCHIVE_UNSAFE'],
            'absolute path' => [fn (ZipBuilder $z): ZipBuilder => $z->file('/etc/passwd', 'x'), 'SOURCE_ARCHIVE_UNSAFE'],
            'symlink' => [fn (ZipBuilder $z): ZipBuilder => $z->file('app.py', 'x')->symlink('link', '/etc/passwd'), 'SOURCE_ARCHIVE_UNSAFE'],
            'another commit' => [fn (ZipBuilder $z): ZipBuilder => $z->file('app.py', 'x')->comment(str_repeat('d', 40)), 'SOURCE_ARCHIVE_INVALID'],
        ];
    }

    /** @param  callable(ZipBuilder): ZipBuilder  $build */
    #[DataProvider('unsafeArchives')]
    public function test_unsafe_archives_create_nothing_and_refund_the_quota(callable $build, string $code): void
    {
        $this->connect(RepositoryProviderKey::GitLab);
        $this->providers->archives[FakeProviders::SHA] = $build(new ZipBuilder)->build();

        $import = $this->import();

        $this->assertSame([GitHubImportStatus::Failed, $code], [$import->status, $import->failure_code]);
        $this->assertSame(0, SourceSnapshot::query()->count());
        $this->assertSame([], $this->stored());
        $this->assertSame(0, $this->used(), 'a failed import gives its quota unit back');
    }

    public function test_malformed_and_oversized_archives_are_refused_without_storing_anything(): void
    {
        $this->connect(RepositoryProviderKey::Bitbucket);
        $this->providers->archives[FakeProviders::SHA] = 'not a zip at all';
        $this->assertSame('SOURCE_ARCHIVE_INVALID', $this->import()->failure_code);

        // Over the archive limit: refused while streaming, whatever Content-Length says.
        config(['codedna.sources.limits.archive_bytes' => 1024]);
        $this->providers->archives[FakeProviders::SHA] = (new ZipBuilder)->file('big.py', random_bytes(4096))->comment(FakeProviders::SHA)->build();
        $this->assertSame('SOURCE_ARCHIVE_TOO_LARGE', $this->import()->failure_code);
        $this->providers->override = fn ($request) => str_contains($request->url(), '/get/')
            ? Http::response(str_repeat('A', 4096), 200, ['Content-Type' => 'application/zip', 'Content-Length' => '10'])
            : null;
        $this->assertSame('SOURCE_ARCHIVE_TOO_LARGE', $this->import()->failure_code);
        $this->assertSame([], $this->stored());
    }

    public function test_a_wrong_content_type_or_a_redirect_elsewhere_is_never_stored_or_followed(): void
    {
        $this->connect(RepositoryProviderKey::Bitbucket);
        $this->providers->override = fn ($request) => str_contains($request->url(), '/get/') ? Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html']) : null;
        $this->assertSame('PROVIDER_IMPORT_FAILED', $this->import()->failure_code);

        $this->providers->override = fn ($request) => str_contains($request->url(), '/get/')
            ? Http::response('', 302, ['Location' => 'https://evil.example/steal.zip'])
            : null;
        $this->assertSame('PROVIDER_IMPORT_FAILED', $this->import()->failure_code);
        $this->assertSame([], $this->providers->requestsTo('evil.example'), 'a redirect outside the archive origins is never fetched');
    }

    public function test_a_redirect_to_an_allowed_archive_origin_is_followed_without_the_token(): void
    {
        config(['codedna.repository_providers.bitbucket.archive_origins' => [FakeProviders::BITBUCKET_WEB, 'https://cdn.bitbucket.test']]);
        $this->connect(RepositoryProviderKey::Bitbucket);
        $this->providers->override = function ($request) {
            if (str_contains($request->url(), '/get/')) {
                return Http::response('', 302, ['Location' => 'https://cdn.bitbucket.test/archive/signed.zip']);
            }
            if (str_starts_with($request->url(), 'https://cdn.bitbucket.test/')) {
                return Http::response($this->providers->archives[FakeProviders::SHA], 200, ['Content-Type' => 'application/zip']);
            }

            return null;
        };

        $this->assertSame(GitHubImportStatus::Succeeded, $this->import()->status);
        $cdn = $this->providers->requestsTo('cdn.bitbucket.test');
        $this->assertCount(1, $cdn);
        $this->assertSame([], $cdn[0]->header('Authorization'), 'the token is sent to the provider only');
    }

    #[DataProvider('providers')]
    public function test_provider_failures_become_safe_failure_codes(RepositoryProviderKey $provider): void
    {
        $this->connect($provider);
        $key = $provider === RepositoryProviderKey::GitLab ? 'gitlab:'.FakeProviders::GITLAB_PROJECT : 'bitbucket:'.FakeProviders::BB_REPOSITORY;

        $this->providers->branches[$key] = [];
        $this->assertSame('PROVIDER_BRANCH_NOT_FOUND', $this->import()->failure_code);

        $this->providers->branches[$key] = ['main' => FakeProviders::SHA];
        $this->providers->override = fn () => Http::response([], 429, ['Retry-After' => '30']);
        // Refused at request time with the same code, and nothing charged.
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/repository-provider/imports")
            ->assertStatus(429)->assertJsonPath('error.code', 'PROVIDER_RATE_LIMITED');
        $this->providers->override = null;
        $id = $this->request();
        $this->providers->override = fn () => Http::response([], 429, ['Retry-After' => '30']);
        $this->assertSame('PROVIDER_RATE_LIMITED', $this->runJob($id)->failure_code);

        $this->providers->override = null;
        $id = $this->request();
        $this->providers->override = fn () => Http::response('', 503);
        $this->assertSame('PROVIDER_UNAVAILABLE', $this->runJob($id)->failure_code);

        // One transient failure is retried once and succeeds.
        $this->providers->override = null;
        $id = $this->request();
        $calls = 0;
        $this->providers->override = function () use (&$calls) {
            return $calls++ === 0 ? Http::response('', 503) : null;
        };
        $this->assertSame(GitHubImportStatus::Succeeded, $this->runJob($id)->status);
        $this->assertSame(1, $this->used(), 'only the successful import keeps its unit');
    }

    public function test_revoked_credentials_or_an_unlinked_account_fail_the_import(): void
    {
        $this->connect(RepositoryProviderKey::GitLab);
        $id = $this->request();
        RepositoryProviderAccount::query()->delete();
        $this->assertSame('PROVIDER_AUTH_REQUIRED', $this->runJob($id)->failure_code);

        ProviderFixtures::account($this->owner, RepositoryProviderKey::GitLab);
        $id = $this->request();
        $this->providers->validTokens = ['rotated-elsewhere'];
        $this->assertSame('PROVIDER_AUTH_REQUIRED', $this->runJob($id)->failure_code);
    }

    public function test_disconnecting_or_archiving_before_the_job_runs_records_nothing(): void
    {
        $this->connect(RepositoryProviderKey::GitLab);
        $id = $this->request();
        $this->asUser($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/repository-provider")->assertOk();
        $this->assertSame(GitHubImportStatus::Cancelled, $this->runJob($id)->status);
        $this->assertSame(0, SourceSnapshot::query()->count());
    }

    public function test_the_import_quota_is_enforced_and_shared_with_github(): void
    {
        $this->connect(RepositoryProviderKey::GitLab);
        // FREE: 20 repository imports per month, GitHub and GitLab/Bitbucket together.
        DB::table('billing_usage_counters')->insert(['user_id' => $this->owner->id, 'quota_key' => 'GITHUB_IMPORTS', 'period_start' => now()->startOfMonth(), 'used' => 20]);

        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/repository-provider/imports")
            ->assertStatus(402)->assertJsonPath('error.code', 'QUOTA_EXCEEDED')->assertJsonPath('error.details.quota', 'GITHUB_IMPORTS');
        $this->assertSame(0, RepositoryProviderImport::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_imports_are_visible_to_project_viewers_only(): void
    {
        $this->connect(RepositoryProviderKey::GitLab);
        $id = $this->request();
        $stranger = User::factory()->create();
        ProviderFixtures::account($stranger, RepositoryProviderKey::GitLab, providerUserId: '9200');

        $this->asUser($stranger)->getJson("/api/v1/projects/{$this->project->id}/repository-provider/imports")->assertNotFound();
        $this->asUser($stranger)->getJson("/api/v1/projects/{$this->project->id}/repository-provider/imports/{$id}")->assertNotFound();
        $this->asUser($stranger)->postJson("/api/v1/projects/{$this->project->id}/repository-provider/imports")->assertNotFound();
        // Another project's import is not found through this project.
        $other = Project::factory()->for($this->owner)->create();
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$other->id}/repository-provider/imports/{$id}")->assertNotFound();
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/repository-provider/imports")->assertOk()->assertJsonPath('data.0.id', $id);
    }
}
