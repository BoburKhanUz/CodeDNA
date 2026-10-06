<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ZipBuilder;
use Tests\TestCase;

/**
 * Source upload against the real MinIO bucket (integration): each test
 * writes under its own unique key prefix and deletes it afterwards.
 */
final class SourceSnapshotApiTest extends TestCase
{
    use RefreshDatabase;

    private string $prefix;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'phpunit/'.strtolower((string) Str::ulid()).'/';
        config(['codedna.sources.key_prefix' => $this->prefix]);
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('sources');
        Storage::disk('sources')->deleteDirectory(rtrim($this->prefix, '/'));
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function url(Project $project, string $suffix = ''): string
    {
        return "/api/v1/projects/{$project->id}/source-snapshots{$suffix}";
    }

    private function zip(?ZipBuilder $builder = null): UploadedFile
    {
        $path = ($builder ?? (new ZipBuilder)
            ->directory('src/')
            ->file('src/Invoice.php', "<?php\nfinal class Invoice {}\n")
            ->file('src/Payment.php', "<?php\nfinal class Payment {}\n")
            ->file('README.md', "# Billing\n"))->save();
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'source.zip', 'application/zip', null, true);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    private function upload(Project $project, ?UploadedFile $file = null, array $headers = [], ?User $as = null, array $extra = []): TestResponse
    {
        return $this->asUser($as ?? $project->user)
            ->withHeaders($headers)
            ->post($this->url($project), ['archive' => $file ?? $this->zip(), ...$extra]);
    }

    /**
     * @return list<string>
     */
    private function storedObjects(): array
    {
        return Storage::disk('sources')->allFiles(rtrim($this->prefix, '/'));
    }

    public function test_the_owner_uploads_a_zip_and_gets_an_immutable_snapshot(): void
    {
        $project = Project::factory()->create();
        $file = $this->zip();
        $hash = hash_file('sha256', (string) $file->getRealPath());
        $size = filesize((string) $file->getRealPath());

        $response = $this->upload($project, $file);

        $snapshot = SourceSnapshot::query()->sole();
        $response->assertCreated()->assertExactJson(['data' => [
            'id' => $snapshot->id,
            'type' => 'source_snapshot',
            'project_id' => $project->id,
            'version' => 1,
            'source_type' => 'UPLOAD',
            'source_hash' => $hash,
            'size_bytes' => $size,
            'file_count' => 3,
            'primary_language' => 'php',
            'created_at' => $snapshot->created_at?->toIso8601ZuluString(),
        ]]);

        // The object really exists, under a key derived from the snapshot's own ID.
        $expectedKey = "{$this->prefix}projects/{$project->id}/snapshots/{$snapshot->id}/source.zip";
        $this->assertSame('sources', $snapshot->storage_disk);
        $this->assertSame($expectedKey, $snapshot->storage_key);
        $this->assertSame([$expectedKey], $this->storedObjects());
        $this->assertSame($hash, hash('sha256', (string) Storage::disk('sources')->get($expectedKey)));
        $this->assertSame(['archive' => ['format' => 'zip', 'entries' => 4, 'directories' => 1, 'uncompressed_bytes' => $snapshot->metadata['archive']['uncompressed_bytes']]], $snapshot->metadata);
    }

    public function test_the_response_never_exposes_storage_details_or_source(): void
    {
        $project = Project::factory()->create();

        $body = (string) $this->upload($project, $this->zip((new ZipBuilder)->file('app.py', 'SECRET_SOURCE_LINE = 1')))
            ->assertCreated()->getContent();

        $snapshot = SourceSnapshot::query()->sole();
        foreach (['storage', $snapshot->storage_key, 'source.zip', 'sources', 'phpunit', 'minio', (string) config('filesystems.disks.sources.bucket'), 'http', 'SECRET_SOURCE_LINE', 'app.py', 'metadata'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "response leaks \"{$needle}\"");
        }
        $this->assertStringNotContainsString($snapshot->storage_key, (string) $this->asUser($project->user)->getJson($this->url($project))->getContent());
        $this->assertStringNotContainsString($snapshot->storage_key, (string) $this->asUser($project->user)->getJson($this->url($project, "/{$snapshot->id}"))->getContent());
    }

    public function test_versions_increase_per_project_and_identical_content_may_be_uploaded_again(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $first = $this->zip();

        $this->upload($project, $first)->assertCreated()->assertJsonPath('data.version', 1);
        $this->upload($project, $first)->assertCreated()->assertJsonPath('data.version', 2);
        $this->upload($other)->assertCreated()->assertJsonPath('data.version', 1);

        $this->assertSame([2, 1], $project->sourceSnapshots()->orderByDesc('version')->pluck('version')->all());
        $this->assertCount(3, $this->storedObjects(), 'every snapshot has its own object');
    }

    public function test_hash_and_size_are_computed_by_the_server(): void
    {
        $project = Project::factory()->create();
        $file = $this->zip();

        $this->upload($project, $file, extra: [
            'source_hash' => str_repeat('f', 64),
            'size_bytes' => 1,
            'file_count' => 999,
            'storage_key' => '../../etc/passwd',
            'version' => 42,
        ])->assertCreated()
            ->assertJsonPath('data.source_hash', hash_file('sha256', (string) $file->getRealPath()))
            ->assertJsonPath('data.size_bytes', filesize((string) $file->getRealPath()))
            ->assertJsonPath('data.file_count', 3)
            ->assertJsonPath('data.version', 1);

        $this->assertStringStartsWith($this->prefix.'projects/', SourceSnapshot::query()->sole()->storage_key);
    }

    public function test_primary_language_is_null_when_no_source_file_is_recognized(): void
    {
        $project = Project::factory()->create();

        $this->upload($project, $this->zip((new ZipBuilder)->file('README.md', '# x')->file('notes.txt', 'y')))
            ->assertCreated()
            ->assertJsonPath('data.primary_language', null)
            ->assertJsonPath('data.file_count', 2);
    }

    public function test_a_non_owner_cannot_upload_list_or_view_snapshots(): void
    {
        $project = Project::factory()->create();
        $snapshot = SourceSnapshot::factory()->for($project)->create();
        $stranger = User::factory()->create();

        $this->upload($project, as: $stranger)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->asUser($stranger)->getJson($this->url($project))->assertNotFound();
        $this->asUser($stranger)->getJson($this->url($project, "/{$snapshot->id}"))->assertNotFound();

        $this->assertSame(1, SourceSnapshot::query()->count());
        $this->assertSame([], $this->storedObjects());
    }

    public function test_a_snapshot_is_only_reachable_through_its_own_project(): void
    {
        $user = User::factory()->create();
        $mine = Project::factory()->for($user)->create();
        $alsoMine = Project::factory()->for($user)->create();
        $snapshot = SourceSnapshot::factory()->for($alsoMine)->create();

        $this->asUser($user)->getJson($this->url($mine, "/{$snapshot->id}"))->assertNotFound();
        $this->asUser($user)->getJson($this->url($alsoMine, "/{$snapshot->id}"))->assertOk()->assertJsonPath('data.id', $snapshot->id);
    }

    public function test_archived_projects_reject_uploads(): void
    {
        $project = Project::factory()->archived()->create();

        $this->upload($project)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROJECT_ARCHIVED');

        $this->assertDatabaseCount('source_snapshots', 0);
        $this->assertSame([], $this->storedObjects());
    }

    public function test_repository_projects_reject_uploads(): void
    {
        $project = Project::factory()->fromRepository()->create();

        $this->upload($project)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_SOURCE_TYPE');

        $this->assertDatabaseCount('source_snapshots', 0);
        $this->assertSame([], $this->storedObjects());
    }

    public function test_an_archive_is_required(): void
    {
        $project = Project::factory()->create();

        $this->asUser($project->user)->post($this->url($project), [])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.archive.0', 'Choose a ZIP archive to upload.');
        $this->asUser($project->user)->post($this->url($project), ['archive' => 'not a file'])
            ->assertUnprocessable();
    }

    /**
     * @return array<string, array{0: \Closure(): ZipBuilder|string, 1: string, 2: int, 3: array<string, int>}>
     */
    public static function rejectedArchives(): array
    {
        $many = static function (): ZipBuilder {
            $zip = new ZipBuilder;
            for ($i = 0; $i < 6; $i++) {
                $zip->file("f{$i}.php", 'x');
            }

            return $zip;
        };

        return [
            'tar.gz' => [static fn (): string => (string) gzencode(str_pad('src/a.php', 512, "\0").str_repeat("\0", 1024)), 'SOURCE_ARCHIVE_INVALID', 422, []],
            'disguised text' => [static fn (): string => 'definitely not a zip archive, only text', 'SOURCE_ARCHIVE_INVALID', 422, []],
            'path traversal' => [static fn (): ZipBuilder => (new ZipBuilder)->file('../evil.php', '<?php system($_GET["c"]);'), 'SOURCE_ARCHIVE_UNSAFE', 422, []],
            'absolute path' => [static fn (): ZipBuilder => (new ZipBuilder)->file('/etc/cron.d/evil', 'x'), 'SOURCE_ARCHIVE_UNSAFE', 422, []],
            'windows path' => [static fn (): ZipBuilder => (new ZipBuilder)->file('C:\\Windows\\evil.bat', 'x'), 'SOURCE_ARCHIVE_UNSAFE', 422, []],
            'symlink' => [static fn (): ZipBuilder => (new ZipBuilder)->file('a.php', 'x')->symlink('config', '/etc/passwd'), 'SOURCE_ARCHIVE_UNSAFE', 422, []],
            'decompression bomb' => [static fn (): ZipBuilder => (new ZipBuilder)->file('bomb.txt', str_repeat('0', 2_000_000), ['declared_size' => 100]), 'SOURCE_ARCHIVE_UNSAFE', 422, []],
            'archive too large' => [static fn (): ZipBuilder => (new ZipBuilder)->file('a.bin', random_bytes(4096), ['method' => 0]), 'SOURCE_ARCHIVE_TOO_LARGE', 413, ['archive_bytes' => 2048]],
            'uncompressed too large' => [static fn (): ZipBuilder => (new ZipBuilder)->file('a.txt', str_repeat('a', 6000))->file('b.txt', str_repeat('b', 6000)), 'SOURCE_UNCOMPRESSED_SIZE_EXCEEDED', 422, ['uncompressed_bytes' => 10_000, 'single_file_bytes' => 10_000]],
            'too many files' => [$many, 'SOURCE_FILE_COUNT_EXCEEDED', 422, ['files' => 5]],
            'single file too large' => [static fn (): ZipBuilder => (new ZipBuilder)->file('big.txt', str_repeat('z', 5000)), 'SOURCE_FILE_TOO_LARGE', 422, ['single_file_bytes' => 4096]],
        ];
    }

    /**
     * @param  \Closure(): (ZipBuilder|string)  $archive
     * @param  array<string, int>  $limits
     */
    #[DataProvider('rejectedArchives')]
    public function test_rejects_unsupported_unsafe_and_oversized_archives(\Closure $archive, string $code, int $status, array $limits): void
    {
        foreach ($limits as $name => $value) {
            config(["codedna.sources.limits.{$name}" => $value]);
        }
        $content = $archive();
        if ($content instanceof ZipBuilder) {
            $file = $this->zip($content);
        } else {
            $path = (string) tempnam(sys_get_temp_dir(), 'codedna-upload-');
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;
            $file = new UploadedFile($path, 'source.zip', 'application/zip', null, true);
        }
        $project = Project::factory()->create();

        $this->upload($project, $file)
            ->assertStatus($status)
            ->assertJsonPath('error.code', $code)
            ->assertJsonMissingPath('error.details');

        $this->assertDatabaseCount('source_snapshots', 0);
        $this->assertSame([], $this->storedObjects(), 'nothing is stored for a rejected archive');
    }

    public function test_errors_and_logs_never_contain_archive_names_or_contents(): void
    {
        Log::spy();
        $project = Project::factory()->create();

        $response = $this->upload($project, $this->zip((new ZipBuilder)->file('../NAME_MARKER<script>.php', 'CONTENT_MARKER')))
            ->assertUnprocessable();

        $this->assertStringNotContainsString('NAME_MARKER', (string) $response->getContent());
        $this->assertStringNotContainsString('CONTENT_MARKER', (string) $response->getContent());
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($project): bool {
            $encoded = (string) json_encode($context);

            return $message === 'Source upload rejected.'
                && $context['project_id'] === $project->id
                && $context['code'] === 'SOURCE_ARCHIVE_UNSAFE'
                && $context['reason'] === 'path_traversal'
                && ! str_contains($encoded, 'MARKER');
        })->once();
    }

    public function test_a_successful_upload_logs_only_safe_metadata(): void
    {
        Log::spy();
        $project = Project::factory()->create();

        $this->upload($project)->assertCreated();

        $snapshot = SourceSnapshot::query()->sole();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Source snapshot created.'
            && $context === [
                'project_id' => $project->id,
                'snapshot_id' => $snapshot->id,
                'user_id' => $project->user_id,
                'version' => 1,
                'size_bytes' => $snapshot->size_bytes,
                'file_count' => 3,
            ])->once();
    }

    public function test_a_failed_database_insert_deletes_the_stored_object(): void
    {
        $project = Project::factory()->create();
        $writtenKeys = [];
        SourceSnapshot::creating(function (SourceSnapshot $snapshot) use (&$writtenKeys): never {
            // The object is already in MinIO when the row is about to be inserted.
            $writtenKeys = $this->storedObjects();
            throw new RuntimeException('Simulated database failure.');
        });

        $this->upload($project)->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertCount(1, $writtenKeys, 'the object had been stored');
        $this->assertSame([], $this->storedObjects(), 'and was deleted after the insert failed');
        $this->assertDatabaseCount('source_snapshots', 0);
    }

    public function test_cleanup_never_touches_existing_snapshots(): void
    {
        $project = Project::factory()->create();
        $this->upload($project)->assertCreated();
        $existing = $this->storedObjects();

        SourceSnapshot::creating(static fn (): never => throw new RuntimeException('Simulated database failure.'));
        $this->upload($project)->assertStatus(500);

        $this->assertSame($existing, $this->storedObjects());
    }

    public function test_an_object_that_cannot_be_deleted_is_logged_by_identity_only(): void
    {
        Log::spy();
        $project = Project::factory()->create();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('writeStream')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('connection reset; secret=hunter2'));
        Storage::set('sources', $disk);
        SourceSnapshot::creating(static fn (): never => throw new RuntimeException('Simulated database failure.'));

        $this->upload($project)->assertStatus(500);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($project): bool {
            return $message === 'Orphaned source object could not be deleted.'
                && $context['project_id'] === $project->id
                && str_starts_with($context['storage_key'], $this->prefix."projects/{$project->id}/snapshots/")
                && $context['exception'] === RuntimeException::class
                && ! str_contains((string) json_encode($context), 'hunter2');
        })->once();
    }

    public function test_storage_outages_are_reported_without_creating_a_snapshot(): void
    {
        $project = Project::factory()->create();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('writeStream')->once()->andThrow(new RuntimeException('endpoint http://minio:9000 unreachable'));
        Storage::set('sources', $disk);

        $response = $this->upload($project)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');

        $this->assertStringNotContainsString('minio', (string) $response->getContent());
        $this->assertDatabaseCount('source_snapshots', 0);
    }

    public function test_a_project_archived_during_the_upload_rejects_it_and_cleans_up(): void
    {
        $project = Project::factory()->create();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('writeStream')->once()->andReturnUsing(function () use ($project): bool {
            DB::table('projects')->where('id', $project->id)->update(['status' => 'ARCHIVED']);

            return true;
        });
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('sources', $disk);

        $this->upload($project)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        $this->assertDatabaseCount('source_snapshots', 0);
    }

    public function test_a_retry_with_the_same_idempotency_key_returns_the_original_snapshot(): void
    {
        $project = Project::factory()->create();
        $file = $this->zip();
        $key = (string) Str::uuid();

        $first = $this->upload($project, $file, ['Idempotency-Key' => $key])->assertCreated();
        $retry = $this->upload($project, $file, ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data'), $retry->json('data'));
        $this->assertDatabaseCount('source_snapshots', 1);
        $this->assertCount(1, $this->storedObjects());
        $this->assertSame(hash('sha256', $key), SourceSnapshot::query()->sole()->idempotency_key_hash);

        // A new key (or none) is a new upload, even for identical bytes.
        $this->upload($project, $file, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.version', 2);
        $this->upload($project, $file)->assertCreated()->assertJsonPath('data.version', 3);
    }

    public function test_reusing_an_idempotency_key_for_different_content_is_rejected(): void
    {
        $project = Project::factory()->create();
        $key = (string) Str::uuid();
        $this->upload($project, $this->zip(), ['Idempotency-Key' => $key])->assertCreated();

        $this->upload($project, $this->zip((new ZipBuilder)->file('other.php', 'different')), ['Idempotency-Key' => $key])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertDatabaseCount('source_snapshots', 1);
        $this->assertCount(1, $this->storedObjects());
    }

    public function test_idempotency_keys_are_scoped_to_the_project(): void
    {
        $user = User::factory()->create();
        $first = Project::factory()->for($user)->create();
        $second = Project::factory()->for($user)->create();
        $key = (string) Str::uuid();

        $this->upload($first, null, ['Idempotency-Key' => $key])->assertCreated();
        $this->upload($second, null, ['Idempotency-Key' => $key])->assertCreated();
    }

    public function test_malformed_idempotency_keys_are_rejected(): void
    {
        $project = Project::factory()->create();

        foreach (['short', str_repeat('k', 129), 'has spaces in it', 'semi;colon;key'] as $key) {
            $this->upload($project, null, ['Idempotency-Key' => $key])
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'BAD_REQUEST');
        }
        $this->assertDatabaseCount('source_snapshots', 0);
    }

    public function test_snapshots_are_listed_newest_first_with_pagination(): void
    {
        $project = Project::factory()->create();
        SourceSnapshot::factory()->count(3)->for($project)->create();
        SourceSnapshot::factory()->create();

        $response = $this->asUser($project->user)->getJson($this->url($project).'?per_page=2')->assertOk();

        $this->assertSame([3, 2], array_column($response->json('data'), 'version'));
        $this->assertSame(['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $response->json('meta'));
        foreach ($response->json('data') as $item) {
            $this->assertSame(['id', 'type', 'project_id', 'version', 'source_type', 'source_hash', 'size_bytes', 'file_count', 'primary_language', 'created_at'], array_keys($item));
        }
    }

    public function test_snapshots_cannot_be_changed_or_deleted_through_the_api(): void
    {
        $project = Project::factory()->create();
        $snapshot = SourceSnapshot::factory()->for($project)->create();

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->asUser($project->user)->json($method, $this->url($project, "/{$snapshot->id}"), ['version' => 9])
                ->assertStatus(405);
        }
        $this->assertSame($snapshot->version, $snapshot->fresh()?->version);
    }

    public function test_uploading_never_executes_archive_contents(): void
    {
        $marker = sys_get_temp_dir().'/codedna-executed-'.Str::random(12);
        $payload = "<?php file_put_contents('{$marker}', 'executed');";
        $project = Project::factory()->create();

        $this->upload($project, $this->zip((new ZipBuilder)
            ->file('index.php', $payload)
            ->file('composer.json', json_encode(['scripts' => ['post-install-cmd' => "touch {$marker}"]]) ?: '{}')
            ->file('package.json', json_encode(['scripts' => ['postinstall' => "touch {$marker}"]]) ?: '{}')
            ->file('setup.py', "open('{$marker}', 'w').write('x')")
            ->file('run.sh', "#!/bin/sh\ntouch {$marker}\n", ['external' => 0o100755 << 16])))
            ->assertCreated();

        $this->assertFileDoesNotExist($marker);
    }

    public function test_uploads_are_rate_limited_per_user(): void
    {
        $project = Project::factory()->create();
        $limit = config('codedna.rate_limits.source_upload_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            // Failed attempts count too.
            $this->asUser($project->user)->post($this->url($project), [])->assertUnprocessable();
        }

        $this->upload($project)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
        $this->assertDatabaseCount('source_snapshots', 0);
    }

    public function test_uploads_require_authentication(): void
    {
        $project = Project::factory()->create();

        $this->fromBrowser()->post($this->url($project), ['archive' => $this->zip()])
            ->assertUnauthorized();
        $this->assertSame([], $this->storedObjects());
    }
}
