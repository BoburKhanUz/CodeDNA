<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11, CodeDNA scoring engine (docs/architecture/dna-scoring-v1.md):
     *
     * - one snapshot per (run, scoring version) instead of one per run, so a
     *   later scoring version can score the same run again without touching
     *   history;
     * - the source snapshot the DNA was computed from, tied to the run's
     *   project by a composite foreign key;
     * - data_quality, on the same 0–1, 4-decimal scale as overall_score.
     *
     * Additive: existing rows keep their values; source_snapshot_id is
     * backfilled from their run.
     */
    public function up(): void
    {
        Schema::table('dna_snapshots', function (Blueprint $table) {
            $table->ulid('source_snapshot_id')->nullable()->after('analysis_run_id');
            $table->decimal('data_quality', 5, 4)->nullable()->after('overall_score');
        });

        DB::statement(<<<'SQL'
            UPDATE dna_snapshots
            SET source_snapshot_id = analysis_runs.source_snapshot_id
            FROM analysis_runs
            WHERE analysis_runs.id = dna_snapshots.analysis_run_id
        SQL);

        Schema::table('dna_snapshots', function (Blueprint $table) {
            $table->ulid('source_snapshot_id')->nullable(false)->change();
            $table->dropUnique(['analysis_run_id']);
            $table->unique(['analysis_run_id', 'scoring_version']);
            $table->foreign(['source_snapshot_id', 'project_id'])
                ->references(['id', 'project_id'])->on('source_snapshots')
                ->restrictOnDelete();
            $table->index('source_snapshot_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE dna_snapshots
                ADD CONSTRAINT dna_snapshots_data_quality_range CHECK (data_quality IS NULL OR data_quality BETWEEN 0 AND 1)
        SQL);
    }

    /**
     * Fails if a run has snapshots for more than one scoring version: that
     * history cannot fit the old one-per-run shape and is not discarded.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE dna_snapshots DROP CONSTRAINT dna_snapshots_data_quality_range');

        Schema::table('dna_snapshots', function (Blueprint $table) {
            $table->dropForeign(['source_snapshot_id', 'project_id']);
            $table->dropIndex(['source_snapshot_id']);
            $table->dropUnique(['analysis_run_id', 'scoring_version']);
            $table->unique('analysis_run_id');
            $table->dropColumn(['source_snapshot_id', 'data_quality']);
        });
    }
};
