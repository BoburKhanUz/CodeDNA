<?php

declare(strict_types=1);

namespace App\Actions\Snapshots;

use App\Models\SourceSnapshot;

/**
 * Result of StoreUploadedSource: the snapshot, and whether this request
 * created it (false for an idempotent replay).
 */
final readonly class StoredSource
{
    public function __construct(public SourceSnapshot $snapshot, public bool $created) {}
}
