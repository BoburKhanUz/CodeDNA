<?php

declare(strict_types=1);

namespace App\Actions\Sources;

use App\Actions\Snapshots\RecordSourceSnapshot;
use App\Actions\Snapshots\StoreUploadedSource;
use App\Enums\SourceType;
use App\Exceptions\SourceArchiveRejected;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Support\Sources\ArchiveSummary;
use App\Support\Sources\LanguageGuesser;
use App\Support\Sources\SourceArchiveLimits;
use App\Support\Sources\ZipArchiveInspector;
use App\Support\Sources\ZipComment;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The provider-independent half of a repository import (Phase 28,
 * docs/integrations/provider-architecture.md#import-pipeline), shared by the
 * GitHub import and the GitLab / Bitbucket Cloud import:
 *
 * - inspect a downloaded archive with the same ZipArchiveInspector and limits
 *   as uploads (traversal, symlinks, bombs, sizes, file counts), and check
 *   the commit git recorded as the ZIP comment;
 * - store it privately under the project's snapshot key;
 * - record the immutable REPOSITORY source snapshot with its provenance;
 * - discard an object that ends up belonging to no snapshot.
 *
 * Nothing is extracted or executed: the archive is only read as ZIP
 * structures. Callers keep their own lifecycle, locks and idempotency.
 */
final readonly class RepositoryArchives
{
    public function __construct(
        private RecordSourceSnapshot $recordSourceSnapshot,
        private StoreUploadedSource $uploads,
        private LanguageGuesser $languageGuesser,
        private FilesystemFactory $filesystems,
        private Repository $config,
    ) {}

    public function limits(): SourceArchiveLimits
    {
        return SourceArchiveLimits::fromConfig((array) $this->config->get('codedna.sources.limits'));
    }

    /**
     * @throws SourceArchiveRejected
     */
    public function inspect(string $path, string $commitSha): ArchiveSummary
    {
        $summary = (new ZipArchiveInspector($this->limits()))->inspect($path);
        $comment = ZipComment::read($path);
        // git archive records the commit as the ZIP comment: it must be the commit asked for.
        if ($comment !== null && preg_match('/^[0-9a-f]{40}$/', $comment) === 1 && $comment !== $commitSha) {
            throw SourceArchiveRejected::invalid('commit_mismatch');
        }

        return $summary;
    }

    /**
     * Stores the archive privately under a key derived from a new snapshot ID.
     *
     * @return array{disk: string, key: string, id: string}
     */
    public function store(Project $project, string $path): array
    {
        $snapshotId = strtolower((string) Str::ulid());
        $disk = (string) $this->config->get('codedna.sources.disk');
        $key = $this->uploads->storageKey($project, $snapshotId);
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The downloaded archive could not be read.');
        }
        try {
            $this->filesystems->disk($disk)->writeStream($key, $stream, ['ContentType' => 'application/zip']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return ['disk' => $disk, 'key' => $key, 'id' => $snapshotId];
    }

    /**
     * Records the REPOSITORY source snapshot of a stored archive.
     *
     * @param  array{disk: string, key: string, id: string}  $stored
     * @param  array<string, mixed>  $provenance
     */
    public function record(Project $project, string $path, ArchiveSummary $summary, array $stored, array $provenance): SourceSnapshot
    {
        return $this->recordSourceSnapshot->handle(
            project: $project,
            sourceType: SourceType::Repository,
            storageDisk: $stored['disk'],
            storageKey: $stored['key'],
            sourceHash: (string) hash_file('sha256', $path),
            sizeBytes: (int) filesize($path),
            fileCount: $summary->fileCount,
            primaryLanguage: $this->languageGuesser->guess($summary->filePaths),
            metadata: [
                'archive' => [
                    'format' => 'zip',
                    'entries' => $summary->entryCount,
                    'directories' => $summary->directoryCount,
                    'uncompressed_bytes' => $summary->uncompressedBytes,
                ],
                'provenance' => $provenance,
            ],
            id: $stored['id'],
        );
    }

    /**
     * Deletes an object that belongs to no snapshot. Never throws.
     *
     * @param  array{disk: string, key: string, id: string}|null  $stored
     */
    public function discard(?array $stored, string $logPrefix): void
    {
        if ($stored === null) {
            return;
        }
        try {
            $this->filesystems->disk($stored['disk'])->delete($stored['key']);
        } catch (Throwable $e) {
            Log::warning($logPrefix.'.orphaned_object', ['storage_disk' => $stored['disk'], 'storage_key' => $stored['key'], 'exception' => $e::class]);
        }
    }
}
