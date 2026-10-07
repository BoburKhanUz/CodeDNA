<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GitHubImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One GitHub import: what was imported (repository, branch, commit), how it
 * ended, and the source snapshot it produced or reused. Load it with its
 * sourceSnapshot.
 *
 * @mixin GitHubImport
 */
final class GitHubImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->source_snapshot_id === null ? null : $this->sourceSnapshot;

        return [
            'id' => $this->id,
            'type' => 'github_import',
            'project_id' => $this->project_id,
            'status' => $this->status->value,
            'repository' => $this->repository_full_name,
            'ref' => $this->ref,
            'commit_sha' => $this->commit_sha,
            'failure_code' => $this->failure_code,
            'source_snapshot' => $snapshot === null ? null : ['id' => $snapshot->id, 'version' => $snapshot->version],
            // false: an earlier import of the same commit already created the snapshot.
            'created_snapshot' => $this->created_snapshot,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
