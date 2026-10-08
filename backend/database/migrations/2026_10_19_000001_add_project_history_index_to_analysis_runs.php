<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 26 (docs/performance/database-performance.md#indexes): a project's
 * successful analyses in history order.
 *
 * Historical DNA (Phase 20) and growth (Phase 18) order a project's
 * snapshots by their analysis run's completion: (completed_at, id), newest
 * first. Without this index every history page, the newest-assessment
 * lookup and the neighbours of a point sorted the project's whole history
 * (or scanned analysis_runs). With it they walk the index and stop after
 * the page: measured 11.4 ms → 0.3 ms for a history page of a project with
 * 10,000 analyses, independent of the history's length.
 *
 * Partial (SUCCEEDED only): history never reads other runs, and the index
 * stays small. Built CONCURRENTLY outside a transaction, so writes to
 * analysis_runs are never blocked while it builds on a large table.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS analysis_runs_project_history_idx
            ON analysis_runs (project_id, completed_at DESC, id DESC)
            WHERE status = 'SUCCEEDED'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS analysis_runs_project_history_idx');
    }
};
