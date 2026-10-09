<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Actions\Sources\RepositoryArchives;
use App\Enums\GitHub\GitHubImportStatus;
use App\Enums\Repositories\ProviderImportFailure;
use App\Exceptions\ApiException;
use App\Exceptions\SourceArchiveRejected;
use App\Models\Project;
use App\Models\RepositoryProviderConnection;
use App\Models\RepositoryProviderImport;
use App\Models\SourceSnapshot;
use App\Services\Repositories\ProviderErrors;
use App\Services\Repositories\ProviderException;
use App\Services\Repositories\ProviderRepository;
use App\Services\Repositories\ProviderUserAccess;
use App\Services\Repositories\RepositoryProvider;
use App\Services\Repositories\RepositoryProviders;
use App\Support\Sources\ArchiveSummary;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one queued GitLab or Bitbucket import (Phase 28,
 * docs/integrations/provider-architecture.md#import-pipeline). The same
 * lifecycle as a GitHub import, and the same shared archive core:
 *
 *  1. claim: QUEUED → RUNNING under a row lock (a duplicate job does nothing);
 *  2. the requesting user's token (refreshed if needed; never in the queue payload);
 *  3. read the repository by its stable ID and resolve the branch to a commit;
 *  4. reuse the project's snapshot of that commit from this repository, if any;
 *  5. otherwise download exactly that commit's archive (provider origins only,
 *     streamed, capped at the archive limit), inspect it with the upload
 *     inspector and limits, check the commit recorded in it, store it privately;
 *  6. under the project lock: record the immutable snapshot (or reuse one
 *     created meanwhile), finish the import, update the connection.
 *
 * Network calls run outside database transactions. Nothing is extracted or
 * executed, and no analysis is started.
 */
final readonly class RunProviderImport
{
    public function __construct(
        private RepositoryProviders $providers,
        private ProviderUserAccess $access,
        private RepositoryArchives $archives,
        private ConnectionInterface $db,
    ) {}

    public function handle(string $importId): ?RepositoryProviderImport
    {
        $import = $this->claim($importId);
        if ($import === null) {
            return null;
        }

        $archive = null;
        try {
            $connection = RepositoryProviderConnection::query()->findOrFail($import->connection_id);
            if (! $connection->isActive()) {
                return $this->finish($import, GitHubImportStatus::Cancelled);
            }
            if (! Project::query()->findOrFail($import->project_id)->isActive()) {
                return $this->fail($import, ProviderImportFailure::ProjectArchived);
            }

            $provider = $this->providers->get($import->provider);
            try {
                $token = $this->access->token($provider, $import->requested_by);
            } catch (ApiException) {
                return $this->fail($import, ProviderImportFailure::AuthRequired);
            } catch (ProviderException $e) {
                return $this->fail($import, ProviderErrors::failure($e, $provider->key(), ProviderImportFailure::AuthRequired, 'refresh_token'));
            }
            try {
                $repository = $provider->repository($token, $import->repository_id);
            } catch (ProviderException $e) {
                return $this->fail($import, ProviderErrors::failure($e, $provider->key(), ProviderImportFailure::RepositoryNotFound, 'repository'));
            }
            try {
                $sha = $provider->branchHead($token, $repository, $import->ref);
            } catch (ProviderException $e) {
                return $this->fail($import, ProviderErrors::failure($e, $provider->key(), ProviderImportFailure::BranchNotFound, 'branch'));
            }

            if ($this->snapshotOfCommit($import, $sha) !== null) {
                return $this->complete($provider, $import, $repository, $sha, null);
            }

            $archive = (string) tempnam(sys_get_temp_dir(), 'codedna-'.$provider->key()->value.'-');
            try {
                $provider->downloadArchive($token, $repository, $sha, $archive, $this->archives->limits()->archiveBytes);
            } catch (ProviderException $e) {
                return $this->fail($import, ProviderErrors::failure($e, $provider->key(), ProviderImportFailure::RepositoryNotFound, 'archive'));
            }
            unset($token);

            $summary = $this->archives->inspect($archive, $sha);

            return $this->complete($provider, $import, $repository, $sha, ['path' => $archive, 'summary' => $summary]);
        } catch (SourceArchiveRejected $rejection) {
            Log::info('repository_provider.import_archive_rejected', ['import_id' => $import->id, 'code' => $rejection->errorCode->value, 'reason' => $rejection->reason]);

            return $this->fail($import, ProviderImportFailure::from($rejection->errorCode->value));
        } catch (Throwable $e) {
            Log::error('repository_provider.import_failed', ['import_id' => $import->id, 'exception' => $e::class]);

            return $this->fail($import, ProviderImportFailure::ImportFailed);
        } finally {
            if ($archive !== null && is_file($archive)) {
                @unlink($archive);
            }
        }
    }

    /** Marks an import that is no longer running (worker timeout or crash) as failed. */
    public function abandon(string $importId): void
    {
        $import = RepositoryProviderImport::query()->find($importId);
        if ($import !== null && ! $import->status->isTerminal()) {
            $this->fail($import, ProviderImportFailure::ImportFailed);
        }
    }

    private function claim(string $importId): ?RepositoryProviderImport
    {
        return $this->db->transaction(function () use ($importId): ?RepositoryProviderImport {
            $import = RepositoryProviderImport::query()->whereKey($importId)->lockForUpdate()->first();
            if ($import === null || $import->status !== GitHubImportStatus::Queued) {
                return null;
            }
            $import->forceFill(['status' => GitHubImportStatus::Running, 'started_at' => Carbon::now()])->save();

            return $import;
        });
    }

    /**
     * @param  array{path: string, summary: ArchiveSummary}|null  $archive  null to reuse an existing snapshot
     */
    private function complete(RepositoryProvider $provider, RepositoryProviderImport $import, ProviderRepository $repository, string $sha, ?array $archive): RepositoryProviderImport
    {
        $stored = $archive === null ? null : $this->archives->store(Project::query()->findOrFail($import->project_id), $archive['path']);

        try {
            $finished = $this->db->transaction(function () use ($import, $repository, $sha, $archive, $stored): RepositoryProviderImport {
                $project = Project::query()->whereKey($import->project_id)->lockForUpdate()->firstOrFail();
                $connection = RepositoryProviderConnection::query()->whereKey($import->connection_id)->lockForUpdate()->firstOrFail();
                $current = RepositoryProviderImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== GitHubImportStatus::Running) {
                    return $current;
                }
                if (! $connection->isActive()) {
                    return $this->terminal($current, GitHubImportStatus::Cancelled);
                }
                if (! $project->isActive()) {
                    return $this->terminal($current, GitHubImportStatus::Failed, ProviderImportFailure::ProjectArchived);
                }

                $now = Carbon::now();
                $existing = $this->snapshotOfCommit($current, $sha);
                $created = $existing === null && $archive !== null && $stored !== null;
                $snapshot = $existing ?? ($created ? $this->archives->record($project, $archive['path'], $archive['summary'], $stored, [
                    'provider' => $current->provider->value,
                    'repository_id' => $current->repository_id,
                    'repository' => $repository->fullName,
                    'ref' => $current->ref,
                    'commit_sha' => $sha,
                    'import_id' => $current->id,
                    'imported_at' => $now->toIso8601ZuluString(),
                ]) : null);
                if ($snapshot === null) {
                    return $this->terminal($current, GitHubImportStatus::Failed, ProviderImportFailure::ImportFailed);
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
                    ...ConnectProviderRepository::metadata($repository, $now),
                ])->save();

                return $current;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent import of the same commit recorded its snapshot first: reuse it.
            $this->archives->discard($stored, 'repository_provider');

            return $this->complete($provider, $import, $repository, $sha, null);
        } catch (Throwable $e) {
            $this->archives->discard($stored, 'repository_provider');

            throw $e;
        }

        if ($stored !== null && ! $finished->created_snapshot) {
            $this->archives->discard($stored, 'repository_provider');
        }
        Log::info('repository_provider.import_finished', [
            'provider' => $provider->key()->value,
            'import_id' => $finished->id,
            'project_id' => $finished->project_id,
            'status' => $finished->status->value,
            'source_snapshot_id' => $finished->source_snapshot_id,
            'created_snapshot' => $finished->created_snapshot,
        ]);

        return $finished;
    }

    /** The project's snapshot of this commit from this provider repository, if an earlier import created one. */
    private function snapshotOfCommit(RepositoryProviderImport $import, string $sha): ?SourceSnapshot
    {
        $id = RepositoryProviderImport::query()
            ->where('project_id', $import->project_id)
            ->where('provider', $import->provider->value)
            ->where('repository_id', $import->repository_id)
            ->where('commit_sha', $sha)
            ->where('created_snapshot', true)
            ->value('source_snapshot_id');

        return $id === null ? null : SourceSnapshot::query()->find($id);
    }

    private function fail(RepositoryProviderImport $import, ProviderImportFailure $failure): RepositoryProviderImport
    {
        return $this->finish($import, GitHubImportStatus::Failed, $failure);
    }

    private function finish(RepositoryProviderImport $import, GitHubImportStatus $status, ?ProviderImportFailure $failure = null): RepositoryProviderImport
    {
        $finished = $this->db->transaction(function () use ($import, $status, $failure): RepositoryProviderImport {
            $current = RepositoryProviderImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();

            return $current->status->isTerminal() ? $current : $this->terminal($current, $status, $failure);
        });
        Log::info('repository_provider.import_finished', [
            'provider' => $finished->provider->value,
            'import_id' => $finished->id,
            'project_id' => $finished->project_id,
            'status' => $finished->status->value,
            'failure_code' => $finished->failure_code,
        ]);

        return $finished;
    }

    private function terminal(RepositoryProviderImport $import, GitHubImportStatus $status, ?ProviderImportFailure $failure = null): RepositoryProviderImport
    {
        $import->forceFill([
            'status' => $status,
            'failure_code' => $status === GitHubImportStatus::Failed ? ($failure ?? ProviderImportFailure::ImportFailed)->value : null,
            'completed_at' => Carbon::now(),
        ])->save();

        return $import;
    }
}
