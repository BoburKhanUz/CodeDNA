<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 13, competency matrix (docs/architecture/competency-matrix-v1.md):
     * the immutable competency result of one DNA snapshot under one competency
     * version. One row holds all competencies of that version as a JSONB
     * array: the set is small and fixed per version, always read together,
     * and never filtered individually yet (a child table can be added by a
     * later version if that changes).
     *
     * The lineage (DNA snapshot, project, analysis run, source snapshot,
     * owner) is one composite foreign key onto dna_snapshots, so the
     * denormalized columns can never disagree with the DNA snapshot. That key
     * needs a unique index on dna_snapshots: an index only, no change to its
     * rows or columns.
     */
    public function up(): void
    {
        Schema::table('dna_snapshots', function (Blueprint $table) {
            $table->unique(['id', 'project_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'], 'dna_snapshots_lineage_unique');
        });

        Schema::create('competency_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('project_id');
            $table->ulid('dna_snapshot_id');
            $table->ulid('analysis_run_id');
            $table->ulid('source_snapshot_id');
            $table->string('competency_version', 32);
            $table->string('dna_scoring_version', 32);
            $table->char('specification_fingerprint', 64);
            $table->string('status', 32);
            // Ordered list of competency results (specification order).
            $table->jsonb('competencies');
            // Counts per status and level; deliberately no aggregate score.
            $table->jsonb('summary');
            $table->jsonb('provenance');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['dna_snapshot_id', 'project_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'], 'competency_snapshots_lineage_foreign')
                ->references(['id', 'project_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'])->on('dna_snapshots')
                ->restrictOnDelete();

            // One result per DNA snapshot and competency version.
            $table->unique(['dna_snapshot_id', 'competency_version']);
            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE competency_snapshots
                ADD CONSTRAINT competency_snapshots_status_valid CHECK (status IN ('ASSESSED', 'INSUFFICIENT_DATA')),
                ADD CONSTRAINT competency_snapshots_fingerprint_sha256 CHECK (specification_fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT competency_snapshots_competencies_array
                    CHECK (jsonb_typeof(competencies) = 'array' AND jsonb_array_length(competencies) > 0 AND octet_length(competencies::text) <= 65536),
                ADD CONSTRAINT competency_snapshots_summary_object
                    CHECK (jsonb_typeof(summary) = 'object' AND octet_length(summary::text) <= 16384),
                ADD CONSTRAINT competency_snapshots_provenance_object
                    CHECK (jsonb_typeof(provenance) = 'object' AND octet_length(provenance::text) <= 16384)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_snapshots');

        Schema::table('dna_snapshots', function (Blueprint $table) {
            $table->dropUnique('dna_snapshots_lineage_unique');
        });
    }
};
