<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a project. Explicit fields only: the owner ID,
 * internal metadata and storage details are never exposed.
 *
 * @mixin Project
 */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'project',
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'default_branch' => $this->default_branch,
            'source_type' => $this->source_type->value,
            'repository_url' => $this->repository_url,
            'language' => $this->language,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
