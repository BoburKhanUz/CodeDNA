<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GitHubConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project's GitHub connection: the repository as last verified with
 * GitHub, the branch, and the last import. Never an installation ID, token,
 * API URL or storage location.
 *
 * @mixin GitHubConnection
 */
final class GitHubConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'github_connection',
            'project_id' => $this->project_id,
            'status' => $this->status->value,
            'repository' => [
                'id' => $this->repository_id,
                'owner' => $this->repository_owner,
                'name' => $this->repository_name,
                'full_name' => $this->repository_full_name,
                'private' => $this->repository_private,
                'archived' => $this->repository_archived,
                'default_branch' => $this->default_branch,
            ],
            'branch' => $this->branch,
            'last_imported_commit_sha' => $this->last_imported_commit_sha,
            'last_imported_at' => $this->last_imported_at?->toIso8601ZuluString(),
            'metadata_verified_at' => $this->metadata_verified_at->toIso8601ZuluString(),
            'connected_at' => $this->connected_at->toIso8601ZuluString(),
            'disconnected_at' => $this->disconnected_at?->toIso8601ZuluString(),
        ];
    }
}
