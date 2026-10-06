<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SourceSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a source snapshot. Never includes the storage
 * disk, the object key, any URL to the object, or source contents.
 *
 * @mixin SourceSnapshot
 */
final class SourceSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'source_snapshot',
            'project_id' => $this->project_id,
            'version' => $this->version,
            'source_type' => $this->source_type->value,
            'source_hash' => $this->source_hash,
            'size_bytes' => $this->size_bytes,
            'file_count' => $this->file_count,
            'primary_language' => $this->primary_language,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
