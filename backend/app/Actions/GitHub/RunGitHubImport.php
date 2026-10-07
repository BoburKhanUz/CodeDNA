<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Actions\Snapshots\RecordSourceSnapshot;
use App\Actions\Snapshots\StoreUploadedSource;
use App\Enums\GitHub\GitHubFailure;
use App\Enums\GitHub\GitHubImportStatus;
use App\Enums\SourceType;
use App\Exceptions\SourceArchiveRejected;
use App\Models\GitHubConnection;
use App\Models\GitHubImport;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubErrors;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubRepository;
use App\Support\Sources\ArchiveSummary;
use App\Support\Sources\LanguageGuesser;
use App\Support\Sources\SourceArchiveLimits;
use App\Support\Sources\ZipArchiveInspector;
use App\Support\Sources\ZipComment;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs one queued GitHub import (docs/architecture/github-integration-v1.md#import-lifecycle).
 *
 *  1. claim: QUEUED → RUNNING under a row lock (a duplicate job does nothing);
 *  2. mint an installation token for this one repository, read-only, in memory only;
 *  3. read the repository by its numeric ID and resolve the branch to a commit;
 *  4. if this project already has a snapshot of that commit from this
 *     repository, reuse it (no download);
 *  5. otherwise download the archive of exactly that commit (redirects only
 *     to allowed origins, streamed, capped at the archive limit), inspect it
 *     with the same ZipArchiveInspector and limits as uploads, hash it, check
 *     the commit recorded in the archive, and store it privately;
 *  6. under the project lock: record the immutable source snapshot (or
 *     reuse one created meanwhile), finish the import, update the connection.
 *
 * Nothing is extracted or executed; the archive is only ever read as ZIP
 * structures. No analysis is started: POST /analyses stays the way to analyze.
 */
final readonly class RunGitHubImport
{
    public function __construct(
        private GitHubApi $api,
        private RecordSourceSnapshot $recordSourceSnapshot,
        private StoreUploadedSource $uploads,
        private LanguageGuesser $languageGuesser,
        private FilesystemFactory $filesystems,
        private ConnectionInterface $db,
        private Repository $config,
    ) {}

    public function handle(string $importId): ?GitHubImport
    {
        $import = $this->claim($importId);
        if ($import === null) {
            return null;
        }

        $archive = null;
        try {
            $connection = GitHubConnection::query()->findOrFail($import->github_connection_id);
            if (! $connection->isActive()) {
                return $this->finish($import, GitHubImportStatus::Cancelled);
            }
            if (! Project::query()->findOrFail($import->project_id)->isActive()) {
                return $this->fail($import, GitHubFailure::ProjectArchived);
            }

            try {
                $token = $this->api->installationToken($connection->installation_id, $connection->repository_id);
            } catch (GitHubException $e) {
                return $this->fail($import, GitHubErrors::failure($e, GitHubFailure::InstallationRequired, 'installation_token'));
            }
            try {
                $repository = $this->api->repository($token, $connection->repository_id);
            } catch (GitHubException $e) {
                return $this->fail($import, GitHubErrors::failure($e, GitHubFailure::RepositoryNotFound, 'repository'));
            }
            try {
                $sha = $this->api->branchHead($token, $repository, $import->ref);
            } catch (GitHubException $e) {
                return $this->fail($import, GitHubErrors::failure($e, GitHubFailure::BranchNotFound, 'branch'));
            }

            $existing = $this->snapshotOfCommit($import, $sha);
            if ($existing !== null) {
                return $this->complete($import, $repository, $sha, null);
            }

            $archive = (string) tempnam(sys_get_temp_dir(), 'codedna-github-');
            $limits = SourceArchiveLimits::fromConfig((array) $this->config->get('codedna.sources.limits'));
            try {
                $this->api->downloadArchive($token, $repository, $sha, $archive, $limits->archiveBytes);
            } catch (GitHubException $e) {
                return $this->fail($import, GitHubErrors::failure($e, GitHubFailure::RepositoryNotFound, 'archive'));
            }
            unset($token);

            $summary = (new ZipArchiveInspector($limits))->inspect($archive);
            $comment = ZipComment::read($archive);
            // git archive records the commit as the ZIP comment: it must be the commit asked for.
            if ($comment !== null && preg_match('/^[0-9a-f]{40}$/', $comment) === 1 && $comment !== $sha) {
                throw SourceArchiveRejected::invalid('commit_mismatch');
            }

            return $this->complete($import, $repository, $sha, ['path' => $archive, 'summary' => $summary]);
        } catch (SourceArchiveRejected $rejection) {
            Log::info('github.import_archive_rejected', ['import_id' => $import->id, 'code' => $rejection->errorCode->value, 'reason' => $rejection->reason]);

            return $this->fail($import, GitHubFailure::from($rejection->errorCode->value));
        } catch (Throwable $e) {
            Log::error('github.import_failed', ['import_id' => $import->id, 'exception' => $e::class]);

            return $this->fail($import, GitHubFailure::ImportFailed);
        } finally {
            if ($archive !== null && is_file($archive)) {
                @unlink($archive);
            }
        }
    }

    /**
     * Marks an import that is no longer running (worker timeout or crash) as failed.
     */
    public function abandon(string $importId): void
    {
        $import = GitHubImport::query()->find($importId);
        if ($import !== null && ! $import->status->isTerminal()) {
            $this->fail($import, GitHubFailure::ImportFailed);
        }
    }

    private function claim(string $importId): ?GitHubImport
    {
        return $this->db->transaction(function () use ($importId): ?GitHubImport {
            $import = GitHubImport::query()->whereKey($importId)->lockForUpdate()->first();
            if ($import === null || $import->status !== GitHubImportStatus::Queued) {
                return null;
            }
            $import->forceFill(['status' => GitHubImportStatus::Running, 'started_at' => Carbon::now()])->save();

            return $import;
        });
    }

    /**
     * Records the snapshot (or reuses the project's snapshot of this commit)
     * and finishes the import, under the project lock.
     *
     * @param  array{path: string, summary: ArchiveSummary}|null  $archive  null to reuse an existing snapshot
     */
    private function complete(GitHubImport $import, GitHubRepository $repository, string $sha, ?array $archive): GitHubImport
    {
        $stored = $archive === null ? null : $this->store($import, $archive['path']);

        try {
            $finished = $this->db->transaction(function () use ($import, $repository, $sha, $archive, $stored): GitHubImport {
                $project = Project::query()->whereKey($import->project_id)->lockForUpdate()->firstOrFail();
                $connection = GitHubConnection::query()->whereKey($import->github_connection_id)->lockForUpdate()->firstOrFail();
                $current = GitHubImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== GitHubImportStatus::Running) {
                    return $current;
                }
                if (! $connection->isActive()) {
                    return $this->terminal($current, GitHubImportStatus::Cancelled);
                }
                if (! $project->isActive()) {
                    return $this->terminal($current, GitHubImportStatus::Failed, GitHubFailure::ProjectArchived);
                }

                $now = Carbon::now();
                $existing = $this->snapshotOfCommit($current, $sha);
                $created = $existing === null && $archive !== null && $stored !== null;
                $snapshot = $existing ?? ($created ? $this->record($project, $current, $repository, $sha, $archive['path'], $archive['summary'], $stored) : null);
                if ($snapshot === null) {
                    // The snapshot this import expected to reuse no longer qualifies: try again from the start.
                    return $this->terminal($current, GitHubImportStatus::Failed, GitHubFailure::ImportFailed);
                }

                $current->forceFill([
                    'status' => GitHubImportStatus::Succeeded,
                    'commit_sha' => $sha,
                    'repository_full_name' => $repository->fullName,
                    'source_snapshot_id' => $snapshot->id,
                    'created_snapshot' => $created,
                    'completed_at' => $now,
                ])->save();
                $connection->forceFill([
                    'last_imported_commit_sha' => $sha,
                    'last_imported_at' => $now,
                    ...ConnectGitHubRepository::metadata($repository, $now),
                ])->save();

                return $current;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent import of the same commit recorded its snapshot first: reuse it.
            $this->discard($stored);

            return $this->complete($import, $repository, $sha, null);
        } catch (Throwable $e) {
            // Nothing was recorded: the object just written belongs to no snapshot.
            $this->discard($stored);

            throw $e;
        }

        if ($stored !== null && ! $finished->created_snapshot) {
            $this->discard($stored);
        }
        Log::info('github.import_finished', [
            'import_id' => $finished->id,
            'project_id' => $finished->project_id,
            'status' => $finished->status->value,
            'source_snapshot_id' => $finished->source_snapshot_id,
            'created_snapshot' => $finished->created_snapshot,
        ]);

        return $finished;
    }

    /**
     * The project's snapshot of this commit from this repository, if an earlier import created one.
     */
    private function snapshotOfCommit(GitHubImport $import, string $sha): ?SourceSnapshot
    {
        $id = GitHubImport::query()
            ->where('project_id', $import->project_id)
            ->where('repository_id', $import->repository_id)
            ->where('commit_sha', $sha)
            ->where('created_snapshot', true)
            ->value('source_snapshot_id');

        return $id === null ? null : SourceSnapshot::query()->find($id);
    }

    /**
     * Stores the archive privately under a key derived from a new snapshot ID.
     *
     * @return array{disk: string, key: string, id: string}
     */
    private function store(GitHubImport $import, string $path): array
    {
        $project = Project::query()->findOrFail($import->project_id);
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
     * @param  array{disk: string, key: string, id: string}  $stored
     */
    private function record(Project $project, GitHubImport $import, GitHubRepository $repository, string $sha, string $path, ArchiveSummary $summary, array $stored): SourceSnapshot
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
                'provenance' => [
                    'provider' => 'github',
                    'repository_id' => $repository->id,
                    'repository' => $repository->fullName,
                    'ref' => $import->ref,
                    'commit_sha' => $sha,
                    'import_id' => $import->id,
                    'imported_at' => Carbon::now()->toIso8601ZuluString(),
                ],
            ],
            id: $stored['id'],
        );
    }

    /**
     * @param  array{disk: string, key: string, id: string}|null  $stored
     */
    private function discard(?array $stored): void
    {
        if ($stored === null) {
            return;
        }
        try {
            $this->filesystems->disk($stored['disk'])->delete($stored['key']);
        } catch (Throwable $e) {
            Log::warning('github.orphaned_object', ['storage_disk' => $stored['disk'], 'storage_key' => $stored['key'], 'exception' => $e::class]);
        }
    }

    private function fail(GitHubImport $import, GitHubFailure $failure): GitHubImport
    {
        return $this->finish($import, GitHubImportStatus::Failed, $failure);
    }

    private function finish(GitHubImport $import, GitHubImportStatus $status, ?GitHubFailure $failure = null): GitHubImport
    {
        $finished = $this->db->transaction(function () use ($import, $status, $failure): GitHubImport {
            $current = GitHubImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();

            return $current->status->isTerminal() ? $current : $this->terminal($current, $status, $failure);
        });
        Log::info('github.import_finished', [
            'import_id' => $finished->id,
            'project_id' => $finished->project_id,
            'status' => $finished->status->value,
            'failure_code' => $finished->failure_code,
        ]);

        return $finished;
    }

    private function terminal(GitHubImport $import, GitHubImportStatus $status, ?GitHubFailure $failure = null): GitHubImport
    {
        $import->forceFill([
            'status' => $status,
            'failure_code' => $status === GitHubImportStatus::Failed ? ($failure ?? GitHubFailure::ImportFailed)->value : null,
            'completed_at' => Carbon::now(),
        ])->save();

        return $import;
    }
}
