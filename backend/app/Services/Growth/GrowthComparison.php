<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Enums\Growth\GrowthSnapshotStatus;

/**
 * The result of comparing an assessment with its baseline, before it is
 * stored.
 */
final readonly class GrowthComparison
{
    /**
     * @param  list<array<string, mixed>>  $observations  one per metric, in a fixed order
     * @param  list<string>  $differences  the compatibility fields that differ (INCOMPARABLE)
     * @param  array<string, mixed>  $summary  categorical counts; deliberately no aggregate score
     */
    public function __construct(
        public GrowthSnapshotStatus $status,
        public array $observations,
        public array $differences,
        public array $summary,
    ) {}
}
