<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RepositoryProviderImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One GitLab or Bitbucket import (Phase 28), in the same shape as a GitHub import.
 *
 * @mixin RepositoryProviderImport
 */
final class RepositoryProviderImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->source_snapshot_id === null ? null : $this->sourceSnapshot;

        return [
            'id' => $this->id,
            'type' => 'repository_provider_import',
            'project_id' => $this->project_id,
            'provider' => $this->provider->value,
            'status' => $this->status->value,
            'repository' => $this->repository_full_name,
            'ref' => $this->ref,
            'commit_sha' => $this->commit_sha,
            'failure_code' => $this->failure_code,
            'source_snapshot' => $snapshot === null ? null : ['id' => $snapshot->id, 'version' => $snapshot->version],
            'created_snapshot' => $this->created_snapshot,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
