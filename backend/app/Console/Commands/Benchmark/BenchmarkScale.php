<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use InvalidArgumentException;

/**
 * Dataset sizes (docs/performance/benchmarking.md#scales).
 *
 * Per-request cost is driven by the largest owner (one project's history,
 * one organization's members, projects and audit log), not by the size of a
 * table: every list is read through an index on its owner. Each scale
 * therefore has a broad population and two outliers: a project with a long
 * history and a large organization.
 */
final readonly class BenchmarkScale
{
    public function __construct(
        public string $name,
        public int $users,
        public int $organizations,
        public int $projects,
        public int $analysesPerProject,
        public int $auditEventsPerOrganization,
        public int $longHistoryAnalyses,
        public int $largeOrganizationMembers,
        public int $largeOrganizationProjects,
        public int $largeOrganizationAuditEvents,
    ) {}

    public static function named(string $name): self
    {
        return match ($name) {
            // CI-sized and quick local runs (measured: ~330 MB, ~1 min to seed).
            'small' => new self('small', 1_000, 50, 2_000, 5, 200, 1_000, 500, 500, 100_000),
            // The measurements in docs/performance (measured: ~2.5 GB, ~8 min): the user,
            // organization and outlier sizes of the Phase 26 targets.
            'medium' => new self('medium', 10_000, 1_000, 10_000, 8, 200, 10_000, 2_000, 2_000, 1_000_000),
            // The full Phase 26 targets: 50k projects, 1M analyses and every
            // derived snapshot, 10M audit events. Estimated ~30 GB of disk; not run in Phase 26.
            'large' => new self('large', 10_000, 1_000, 50_000, 20, 9_000, 10_000, 2_000, 2_000, 1_000_000),
            default => throw new InvalidArgumentException("Unknown scale \"{$name}\": small, medium or large."),
        };
    }
}
