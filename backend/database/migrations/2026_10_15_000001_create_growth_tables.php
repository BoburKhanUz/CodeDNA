<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Growth tracking (Phase 18, docs/architecture/growth-tracking-v1.md). An
 * observation layer over existing assessments: these tables are never read
 * by, and never write to, any scoring table.
 *
 * - growth_snapshots: one per (assessment, growth rules version), i.e. per
 *   skill gap snapshot. Its lineage and its baseline's lineage are composite
 *   foreign keys onto the skill gap snapshots' lineage index (Phase 15), so
 *   both assessments belong to the same project and owner and their
 *   snapshots cannot disagree.
 * - growth_observations: one per compared metric of a COMPARED snapshot.
 *
 * Both are immutable (trigger): history is never rewritten; a new rules
 * version creates new snapshots. Additive only; nothing is cascaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('project_id');
            $table->ulid('skill_gap_snapshot_id');
            $table->ulid('competency_snapshot_id');
            $table->ulid('dna_snapshot_id');
            $table->ulid('analysis_run_id');
            $table->ulid('source_snapshot_id');
            $table->timestamp('assessed_at');
            $table->ulid('previous_skill_gap_snapshot_id')->nullable();
            $table->ulid('previous_competency_snapshot_id')->nullable();
            $table->ulid('previous_dna_snapshot_id')->nullable();
            $table->ulid('previous_analysis_run_id')->nullable();
            $table->ulid('previous_source_snapshot_id')->nullable();
            $table->timestamp('previous_assessed_at')->nullable();
            $table->string('rules_version', 32);
            $table->char('rules_fingerprint', 64);
            $table->jsonb('versions');
            $table->jsonb('previous_versions')->nullable();
            $table->jsonb('differences');
            $table->string('status', 32);
            $table->jsonb('summary');
            $table->timestamp('created_at')->useCurrent();

            $lineage = ['id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'];
            $table->foreign(['skill_gap_snapshot_id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'],
                'growth_snapshots_lineage_foreign')->references($lineage)->on('skill_gap_snapshots')->restrictOnDelete();
            // The baseline: same project and owner by construction (shared columns).
            $table->foreign(['previous_skill_gap_snapshot_id', 'project_id', 'previous_competency_snapshot_id', 'previous_dna_snapshot_id',
                'previous_analysis_run_id', 'previous_source_snapshot_id', 'user_id'],
                'growth_snapshots_previous_lineage_foreign')->references($lineage)->on('skill_gap_snapshots')->restrictOnDelete();
            $table->unique(['skill_gap_snapshot_id', 'rules_version'], 'growth_snapshots_assessment_rules_unique');
            // Target of the observations' composite foreign key.
            $table->unique(['id', 'project_id', 'user_id'], 'growth_snapshots_owner_unique');
            $table->index(['project_id', 'assessed_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('previous_skill_gap_snapshot_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE growth_snapshots
                ADD CONSTRAINT growth_snapshots_status_valid CHECK (status IN ('NOT_ESTABLISHED', 'INCOMPARABLE', 'COMPARED')),
                ADD CONSTRAINT growth_snapshots_baseline_all_or_none CHECK (
                    (previous_skill_gap_snapshot_id IS NULL) = (previous_competency_snapshot_id IS NULL)
                    AND (previous_skill_gap_snapshot_id IS NULL) = (previous_dna_snapshot_id IS NULL)
                    AND (previous_skill_gap_snapshot_id IS NULL) = (previous_analysis_run_id IS NULL)
                    AND (previous_skill_gap_snapshot_id IS NULL) = (previous_source_snapshot_id IS NULL)
                    AND (previous_skill_gap_snapshot_id IS NULL) = (previous_assessed_at IS NULL)
                    AND (previous_skill_gap_snapshot_id IS NULL) = (previous_versions IS NULL)),
                ADD CONSTRAINT growth_snapshots_baseline_iff_established CHECK ((status = 'NOT_ESTABLISHED') = (previous_skill_gap_snapshot_id IS NULL)),
                ADD CONSTRAINT growth_snapshots_baseline_earlier CHECK (
                    previous_skill_gap_snapshot_id IS DISTINCT FROM skill_gap_snapshot_id
                    AND previous_analysis_run_id IS DISTINCT FROM analysis_run_id
                    AND (previous_assessed_at IS NULL OR previous_assessed_at <= assessed_at)),
                ADD CONSTRAINT growth_snapshots_differences_iff_incomparable CHECK (
                    jsonb_typeof(differences) = 'array' AND (status = 'INCOMPARABLE') = (jsonb_array_length(differences) > 0)),
                ADD CONSTRAINT growth_snapshots_rules CHECK (rules_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$' AND rules_fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT growth_snapshots_json_bounded CHECK (
                    jsonb_typeof(versions) = 'object' AND octet_length(versions::text) <= 4096
                    AND (previous_versions IS NULL OR (jsonb_typeof(previous_versions) = 'object' AND octet_length(previous_versions::text) <= 4096))
                    AND jsonb_typeof(summary) = 'object' AND octet_length(summary::text) <= 4096)
        SQL);

        Schema::create('growth_observations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('growth_snapshot_id');
            $table->ulid('project_id');
            $table->ulid('user_id');
            $table->unsignedSmallInteger('position');
            $table->string('metric_type', 16);
            $table->string('metric_key', 64);
            $table->string('better', 8);
            $table->string('previous_state', 32);
            $table->string('current_state', 32);
            $table->decimal('previous_value', 5, 4)->nullable();
            $table->decimal('current_value', 5, 4)->nullable();
            $table->decimal('delta', 5, 4)->nullable();
            $table->string('previous_level', 32)->nullable();
            $table->string('current_level', 32)->nullable();
            $table->string('level_change', 8)->nullable();
            $table->decimal('previous_evidence_quality', 5, 4)->nullable();
            $table->decimal('current_evidence_quality', 5, 4)->nullable();
            $table->string('status', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['growth_snapshot_id', 'project_id', 'user_id'], 'growth_observations_snapshot_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('growth_snapshots')
                ->restrictOnDelete();
            $table->unique(['growth_snapshot_id', 'metric_type', 'metric_key'], 'growth_observations_metric_unique');
            $table->unique(['growth_snapshot_id', 'position'], 'growth_observations_position_unique');
            $table->index(['project_id', 'metric_type', 'metric_key']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE growth_observations
                ADD CONSTRAINT growth_observations_type_valid CHECK (metric_type IN ('DNA', 'COMPETENCY', 'SKILL_GAP')),
                ADD CONSTRAINT growth_observations_direction CHECK ((better = 'LOWER') = (metric_type = 'SKILL_GAP') AND better IN ('HIGHER', 'LOWER')),
                ADD CONSTRAINT growth_observations_status_valid CHECK (status IN ('IMPROVED', 'REGRESSED', 'UNCHANGED', 'INSUFFICIENT_EVIDENCE')),
                ADD CONSTRAINT growth_observations_values_all_or_none CHECK (
                    (delta IS NULL) = (previous_value IS NULL) AND (delta IS NULL) = (current_value IS NULL)),
                ADD CONSTRAINT growth_observations_delta_formula CHECK (delta IS NULL OR delta = current_value - previous_value),
                ADD CONSTRAINT growth_observations_classified_needs_delta CHECK (status = 'INSUFFICIENT_EVIDENCE' OR delta IS NOT NULL),
                ADD CONSTRAINT growth_observations_values_range CHECK (
                    (previous_value IS NULL OR previous_value BETWEEN 0 AND 1) AND (current_value IS NULL OR current_value BETWEEN 0 AND 1)
                    AND (previous_evidence_quality IS NULL OR previous_evidence_quality BETWEEN 0 AND 1)
                    AND (current_evidence_quality IS NULL OR current_evidence_quality BETWEEN 0 AND 1)),
                ADD CONSTRAINT growth_observations_level_change CHECK (
                    level_change IS NULL OR (metric_type = 'COMPETENCY' AND level_change IN ('UP', 'DOWN', 'SAME')))
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION growth_refuse_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '%: growth history is immutable', TG_TABLE_NAME USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER growth_snapshots_immutable
                BEFORE UPDATE ON growth_snapshots
                FOR EACH ROW EXECUTE FUNCTION growth_refuse_update();
            CREATE TRIGGER growth_observations_immutable
                BEFORE UPDATE ON growth_observations
                FOR EACH ROW EXECUTE FUNCTION growth_refuse_update();

            -- Observations exist only for compared snapshots.
            CREATE OR REPLACE FUNCTION growth_observations_guard_insert() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM growth_snapshots WHERE id = NEW.growth_snapshot_id AND status = 'COMPARED') THEN
                    RAISE EXCEPTION 'growth_observations: only a COMPARED growth snapshot has observations' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER growth_observations_compared_only
                BEFORE INSERT ON growth_observations
                FOR EACH ROW EXECUTE FUNCTION growth_observations_guard_insert();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_observations');
        Schema::dropIfExists('growth_snapshots');
        DB::unprepared('DROP FUNCTION IF EXISTS growth_observations_guard_insert(); DROP FUNCTION IF EXISTS growth_refuse_update();');
    }
};
