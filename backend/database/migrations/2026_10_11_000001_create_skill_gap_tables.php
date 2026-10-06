<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 14, skill gap analysis (docs/architecture/skill-gap-v1.md):
     *
     * - skill_gap_snapshots: one immutable row per (competency snapshot,
     *   skill gap version, target profile), with versions, fingerprint,
     *   status, summary and provenance;
     * - skill_gap_results: one immutable row per competency, with the
     *   current and target scores, raw gap, materiality and priority as
     *   columns, so later phases can query active gaps by project,
     *   competency, status and priority without unpacking JSON.
     *
     * Lineage is enforced by composite foreign keys (onto a new unique index
     * on competency_snapshots: an index only), and the result rows' numbers
     * by CHECK constraints, including raw_gap = max(target − current, 0).
     */
    public function up(): void
    {
        Schema::table('competency_snapshots', function (Blueprint $table) {
            $table->unique(['id', 'project_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'], 'competency_snapshots_lineage_unique');
        });

        Schema::create('skill_gap_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('project_id');
            $table->ulid('competency_snapshot_id');
            $table->ulid('dna_snapshot_id');
            $table->ulid('analysis_run_id');
            $table->ulid('source_snapshot_id');
            $table->string('skill_gap_version', 32);
            $table->string('target_profile', 64);
            $table->string('target_profile_version', 32);
            $table->string('competency_version', 32);
            $table->string('dna_scoring_version', 32);
            $table->char('specification_fingerprint', 64);
            $table->string('status', 32);
            // Counts per status and priority; deliberately no aggregate gap.
            $table->jsonb('summary');
            $table->jsonb('provenance');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['competency_snapshot_id', 'project_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'], 'skill_gap_snapshots_lineage_foreign')
                ->references(['id', 'project_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'])->on('competency_snapshots')
                ->restrictOnDelete();

            $table->unique(['competency_snapshot_id', 'skill_gap_version', 'target_profile'], 'skill_gap_snapshots_competency_version_profile_unique');
            // Target of the results' composite foreign key.
            $table->unique(['id', 'project_id', 'user_id']);
            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE skill_gap_snapshots
                ADD CONSTRAINT skill_gap_snapshots_status_valid CHECK (status IN ('GAPS_IDENTIFIED', 'NO_MATERIAL_GAPS', 'INSUFFICIENT_DATA')),
                ADD CONSTRAINT skill_gap_snapshots_fingerprint_sha256 CHECK (specification_fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT skill_gap_snapshots_summary_object CHECK (jsonb_typeof(summary) = 'object' AND octet_length(summary::text) <= 16384),
                ADD CONSTRAINT skill_gap_snapshots_provenance_object CHECK (jsonb_typeof(provenance) = 'object' AND octet_length(provenance::text) <= 16384)
        SQL);

        Schema::create('skill_gap_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('skill_gap_snapshot_id');
            $table->ulid('project_id');
            $table->ulid('user_id');
            // Display order: targeted competencies in profile order, then untargeted ones.
            $table->unsignedSmallInteger('position');
            $table->string('competency_key', 64);
            $table->string('status', 32);
            $table->string('competency_status', 32)->nullable();
            $table->decimal('current_score', 5, 4)->nullable();
            $table->decimal('target_score', 5, 4)->nullable();
            $table->decimal('raw_gap', 5, 4)->nullable();
            $table->boolean('material_gap')->nullable();
            $table->string('priority', 16)->nullable();
            $table->boolean('priority_capped')->nullable();
            $table->decimal('evidence_quality', 5, 4)->nullable();
            $table->string('current_level', 32)->nullable();
            // Language limitations and the competency's evidence (source, status, value, score).
            $table->jsonb('evidence');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['skill_gap_snapshot_id', 'project_id', 'user_id'], 'skill_gap_results_snapshot_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('skill_gap_snapshots')
                ->restrictOnDelete();

            $table->unique(['skill_gap_snapshot_id', 'competency_key']);
            $table->unique(['skill_gap_snapshot_id', 'position']);
            $table->index(['project_id', 'competency_key']);
            $table->index(['user_id', 'status', 'priority']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE skill_gap_results
                ADD CONSTRAINT skill_gap_results_status_valid
                    CHECK (status IN ('GAP', 'NO_GAP', 'INSUFFICIENT_EVIDENCE', 'UNSUPPORTED', 'MISSING', 'NOT_TARGETED')),
                ADD CONSTRAINT skill_gap_results_priority_valid CHECK (priority IS NULL OR priority IN ('LOW', 'MEDIUM', 'HIGH')),
                ADD CONSTRAINT skill_gap_results_scores_range CHECK (
                    (current_score IS NULL OR current_score BETWEEN 0 AND 1)
                    AND (target_score IS NULL OR target_score BETWEEN 0 AND 1)
                    AND (raw_gap IS NULL OR raw_gap BETWEEN 0 AND 1)
                    AND (evidence_quality IS NULL OR evidence_quality BETWEEN 0 AND 1)),
                ADD CONSTRAINT skill_gap_results_measured_iff_gap_status CHECK (
                    (status IN ('GAP', 'NO_GAP')) = (current_score IS NOT NULL AND target_score IS NOT NULL AND raw_gap IS NOT NULL AND material_gap IS NOT NULL)),
                ADD CONSTRAINT skill_gap_results_raw_gap_formula CHECK (raw_gap IS NULL OR raw_gap = GREATEST(target_score - current_score, 0)),
                ADD CONSTRAINT skill_gap_results_gap_iff_material CHECK ((status = 'GAP') = COALESCE(material_gap, FALSE)),
                ADD CONSTRAINT skill_gap_results_priority_iff_gap CHECK ((status = 'GAP') = (priority IS NOT NULL AND priority_capped IS NOT NULL)),
                ADD CONSTRAINT skill_gap_results_target_iff_targeted CHECK ((status = 'NOT_TARGETED') = (target_score IS NULL)),
                ADD CONSTRAINT skill_gap_results_evidence_object CHECK (jsonb_typeof(evidence) = 'object' AND octet_length(evidence::text) <= 16384)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_gap_results');
        Schema::dropIfExists('skill_gap_snapshots');

        Schema::table('competency_snapshots', function (Blueprint $table) {
            $table->dropUnique('competency_snapshots_lineage_unique');
        });
    }
};
