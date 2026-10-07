<?php

declare(strict_types=1);

namespace App\Actions\Snapshots;

use App\Enums\Billing\QuotaKey;
use App\Enums\SourceType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Services\Billing\UsageService;
use Illuminate\Database\ConnectionInterface;

/**
 * Records a new immutable source snapshot for a project and assigns its
 * version (1, 2, 3, …). Uploads go through StoreUploadedSource, which stores
 * the archive first and then calls this. Only references are stored: the archive itself must
 * already be in object storage (ADR-003).
 *
 * Runs in a transaction that locks the project row, so concurrent uploads
 * get distinct, gap-free versions. (project_id, version) is also unique in
 * the database.
 */
final readonly class RecordSourceSnapshot
{
    public function __construct(private ConnectionInterface $db, private UsageService $usage) {}

    /**
     * @param  array<string, mixed>  $metadata  descriptive data only, never file contents
     */
    public function handle(
        Project $project,
        SourceType $sourceType,
        string $storageDisk,
        string $storageKey,
        string $sourceHash,
        int $sizeBytes,
        int $fileCount,
        ?string $primaryLanguage = null,
        array $metadata = [],
        ?string $id = null,
        ?string $idempotencyKeyHash = null,
    ): SourceSnapshot {
        return $this->db->transaction(function () use (
            $project, $sourceType, $storageDisk, $storageKey, $sourceHash, $sizeBytes, $fileCount, $primaryLanguage, $metadata,
            $id, $idempotencyKeyHash,
        ): SourceSnapshot {
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw DomainRuleViolation::because("Project {$locked->id} is archived and accepts no new source snapshots.");
            }

            $nextVersion = (int) SourceSnapshot::query()->where('project_id', $locked->id)->max('version') + 1;

            $snapshot = new SourceSnapshot;
            // A pre-generated ID lets the caller store the object under a key
            // derived from the snapshot's own identity before recording it.
            if ($id !== null) {
                $snapshot->setAttribute('id', $id);
            }
            $snapshot->forceFill([
                'project_id' => $locked->id,
                'version' => $nextVersion,
                'source_type' => $sourceType,
                'storage_disk' => $storageDisk,
                'storage_key' => $storageKey,
                'source_hash' => $sourceHash,
                'size_bytes' => $sizeBytes,
                'file_count' => $fileCount,
                'primary_language' => $primaryLanguage,
                'metadata' => $metadata === [] ? null : $metadata,
                'idempotency_key_hash' => $idempotencyKeyHash,
            ]);
            $snapshot->save();
            // Billing (Phase 23): an uploaded archive counts towards the
            // owner's monthly uploads and bytes, in this transaction, so a
            // refusal records nothing. GitHub imports are counted when requested.
            if ($sourceType === SourceType::Upload) {
                $this->usage->consume($locked->user_id, QuotaKey::SourceUploads, 'source_snapshot', $snapshot->id);
                $this->usage->consume($locked->user_id, QuotaKey::SourceUploadBytes, 'source_snapshot', $snapshot->id, max(1, $sizeBytes));
            }

            return $snapshot;
        });
    }
}
