<?php

declare(strict_types=1);

namespace App\Actions\Snapshots;

use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\SourceArchiveRejected;
use App\Http\Errors\ErrorCode;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Support\Sources\ArchiveSummary;
use App\Support\Sources\LanguageGuesser;
use App\Support\Sources\SourceArchiveLimits;
use App\Support\Sources\ZipArchiveInspector;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an uploaded ZIP archive into an immutable source snapshot
 * (docs/architecture/data-flow.md#source-upload):
 *
 *  1. the project must be ACTIVE and an UPLOAD project;
 *  2. the archive is inspected without extracting or executing anything
 *     (ZipArchiveInspector);
 *  3. SHA-256 and size are computed by the server over the archive bytes;
 *  4. a retry with the same Idempotency-Key returns the original snapshot;
 *  5. the archive is stored privately under a key derived from the new
 *     snapshot's ID, then the snapshot row is recorded (RecordSourceSnapshot
 *     assigns the version under a project lock);
 *  6. if recording fails, the object just written is deleted. Its key is
 *     unique to this attempt, so no other snapshot's object is ever touched.
 *
 * Source code is never parsed, executed, logged or stored in PostgreSQL.
 */
final readonly class StoreUploadedSource
{
    public const ARCHIVE_FILE_NAME = 'source.zip';

    public function __construct(
        private RecordSourceSnapshot $recordSourceSnapshot,
        private LanguageGuesser $languageGuesser,
        private FilesystemFactory $filesystems,
        private Repository $config,
    ) {}

    /**
     * @param  string  $path  local path of the uploaded file (PHP's temporary upload file)
     * @param  string|null  $idempotencyKey  the client's Idempotency-Key header, already validated
     */
    public function handle(Project $project, User $actor, string $path, ?string $idempotencyKey = null): StoredSource
    {
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and accepts no new source snapshots.');
        }
        if ($project->source_type !== SourceType::Upload) {
            throw new ApiException(ErrorCode::InvalidSourceType);
        }

        $summary = $this->inspect($project, $actor, $path);
        $sourceHash = (string) hash_file('sha256', $path);
        $sizeBytes = (int) filesize($path);
        $keyHash = $idempotencyKey === null ? null : hash('sha256', $idempotencyKey);

        if ($keyHash !== null && ($existing = $this->findByIdempotencyKey($project, $keyHash)) !== null) {
            return $this->replay($existing, $sourceHash);
        }

        $snapshotId = strtolower((string) Str::ulid());
        $diskName = (string) $this->config->get('codedna.sources.disk');
        $storageKey = $this->storageKey($project, $snapshotId);

        $this->storeObject($diskName, $storageKey, $path, $project, $snapshotId);

        try {
            $snapshot = $this->recordSourceSnapshot->handle(
                project: $project,
                sourceType: SourceType::Upload,
                storageDisk: $diskName,
                storageKey: $storageKey,
                sourceHash: $sourceHash,
                sizeBytes: $sizeBytes,
                fileCount: $summary->fileCount,
                primaryLanguage: $this->languageGuesser->guess($summary->filePaths),
                metadata: ['archive' => [
                    'format' => 'zip',
                    'entries' => $summary->entryCount,
                    'directories' => $summary->directoryCount,
                    'uncompressed_bytes' => $summary->uncompressedBytes,
                ]],
                id: $snapshotId,
                idempotencyKeyHash: $keyHash,
            );
        } catch (Throwable $e) {
            $this->discardObject($diskName, $storageKey, $project, $snapshotId);

            // A concurrent retry with the same key won the race.
            if ($e instanceof UniqueConstraintViolationException && $keyHash !== null
                && ($existing = $this->findByIdempotencyKey($project, $keyHash)) !== null) {
                return $this->replay($existing, $sourceHash);
            }
            // The project was archived while this upload was in flight.
            if ($e instanceof DomainRuleViolation && ! $project->refresh()->isActive()) {
                throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and accepts no new source snapshots.');
            }

            throw $e;
        }

        Log::info('Source snapshot created.', [
            'project_id' => $project->id,
            'snapshot_id' => $snapshot->id,
            'user_id' => $actor->id,
            'version' => $snapshot->version,
            'size_bytes' => $snapshot->size_bytes,
            'file_count' => $snapshot->file_count,
        ]);

        return new StoredSource($snapshot, created: true);
    }

    /**
     * {prefix}projects/{project_id}/snapshots/{snapshot_id}/source.zip — built
     * only from server-generated ULIDs, never from client input.
     */
    public function storageKey(Project $project, string $snapshotId): string
    {
        return $this->config->get('codedna.sources.key_prefix')
            ."projects/{$project->id}/snapshots/{$snapshotId}/".self::ARCHIVE_FILE_NAME;
    }

    private function inspect(Project $project, User $actor, string $path): ArchiveSummary
    {
        $limits = SourceArchiveLimits::fromConfig((array) $this->config->get('codedna.sources.limits'));

        try {
            return (new ZipArchiveInspector($limits))->inspect($path);
        } catch (SourceArchiveRejected $rejection) {
            Log::info('Source upload rejected.', [
                'project_id' => $project->id,
                'user_id' => $actor->id,
                'code' => $rejection->errorCode->value,
                'reason' => $rejection->reason,
                'size_bytes' => @filesize($path) ?: null,
            ]);

            throw new ApiException($rejection->errorCode);
        }
    }

    private function storeObject(string $diskName, string $storageKey, string $path, Project $project, string $snapshotId): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new ApiException(ErrorCode::InternalError);
        }

        try {
            $this->filesystems->disk($diskName)->writeStream($storageKey, $stream, ['ContentType' => 'application/zip']);
        } catch (Throwable $e) {
            Log::error('Source object could not be stored.', [
                'project_id' => $project->id,
                'snapshot_id' => $snapshotId,
                'exception' => $e::class,
            ]);

            throw new ApiException(ErrorCode::ServiceUnavailable, 'Source storage is temporarily unavailable. Try again later.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Best effort: if the delete fails too, log the object's identity (never
     * credentials or contents) so it can be reconciled later.
     */
    private function discardObject(string $diskName, string $storageKey, Project $project, string $snapshotId): void
    {
        try {
            $this->filesystems->disk($diskName)->delete($storageKey);
        } catch (Throwable $e) {
            Log::warning('Orphaned source object could not be deleted.', [
                'storage_disk' => $diskName,
                'storage_key' => $storageKey,
                'project_id' => $project->id,
                'snapshot_id' => $snapshotId,
                'exception' => $e::class,
            ]);
        }
    }

    private function findByIdempotencyKey(Project $project, string $keyHash): ?SourceSnapshot
    {
        return SourceSnapshot::query()
            ->where('project_id', $project->id)
            ->where('idempotency_key_hash', $keyHash)
            ->first();
    }

    /**
     * Same key, same bytes: the original result. Same key, different bytes:
     * a client bug, reported instead of silently returning the wrong snapshot.
     */
    private function replay(SourceSnapshot $existing, string $sourceHash): StoredSource
    {
        if (! hash_equals($existing->source_hash, $sourceHash)) {
            throw new ApiException(ErrorCode::IdempotencyKeyReused);
        }

        return new StoredSource($existing, created: false);
    }
}
