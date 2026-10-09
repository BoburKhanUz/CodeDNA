<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RepositoryProviderConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project's GitLab or Bitbucket connection (Phase 28). No token, no
 * account and no provider URL: only what the provider reported and was verified.
 *
 * @mixin RepositoryProviderConnection
 */
final class RepositoryProviderConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'repository_provider_connection',
            'project_id' => $this->project_id,
            'provider' => $this->provider->value,
            'status' => $this->status->value,
            'repository' => [
                'id' => $this->repository_id,
                'full_name' => $this->repository_full_name,
                'private' => $this->repository_private,
                'archived' => $this->repository_archived,
                'default_branch' => $this->default_branch,
            ],
            'branch' => $this->branch,
            'last_imported_commit_sha' => $this->last_imported_commit_sha,
            'last_imported_at' => $this->last_imported_at?->toIso8601ZuluString(),
            'connected_at' => $this->connected_at->toIso8601ZuluString(),
            'disconnected_at' => $this->disconnected_at?->toIso8601ZuluString(),
        ];
    }
}
